<?php

namespace App\Domains\Enrichment;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\Enrichment\Clients\SiteScraper;
use App\Domains\Enrichment\Data\EnrichmentResult;
use App\Enums\IntegrationProvider;
use App\Events\LeadEnriched;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enrichment waterfall, from free to paid:
 *   1. site scrape (has a domain, no e-mail yet)
 *   2. Hunter.io (still no e-mail)
 *   3. Evolution API (has a phone)
 *
 * Each step records a lead_source for auditing. A failing step does not block the others.
 * Paid steps run only when the project owner connected that provider (BYOK).
 *
 * @return list<string> steps that ran
 */
class LeadEnricher
{
    public function __construct(
        private SiteScraper $siteScraper,
        private HunterClient $hunter,
        private EvolutionClient $evolution,
    ) {}

    /** @return list<string> */
    public function enrich(Lead $lead): array
    {
        $ran = [];
        $config = config('enrichment');
        $jobTitles = $lead->icp->criteria_object->jobTitles;
        $owner = $lead->project->user;
        $hunter = $this->hunter->withCredentials($owner->credentialsFor(IntegrationProvider::Hunter));
        $evolution = $this->evolution->withCredentials($owner->credentialsFor(IntegrationProvider::Evolution));

        if (($config['site_scrape']['enabled'] ?? false) && $lead->domain && ! $lead->email) {
            $ran[] = $this->run($lead, 'site_scrape', fn () => $this->siteScraper->enrich($lead));
        }

        if ($hunter->isConfigured() && $lead->domain && ! $lead->email) {
            $ran[] = $this->run($lead, 'hunter', fn () => $hunter->enrich($lead, $jobTitles));
        }

        if ($evolution->isConfigured() && $lead->phone && $lead->whatsapp_valid === null) {
            $ran[] = $this->run($lead, 'evolution', fn () => $evolution->enrich($lead));
        }

        $lead->forceFill(['enriched_at' => now()])->save();

        $ran = array_values(array_filter($ran));

        if ($ran !== []) {
            LeadEnriched::dispatch($lead, $ran);
        }

        return $ran;
    }

    /** @param  callable(): ?EnrichmentResult  $step */
    private function run(Lead $lead, string $driver, callable $step): ?string
    {
        try {
            $result = $step();
        } catch (Throwable $e) {
            Log::warning("Enrichment [{$driver}] failed for lead {$lead->id}: {$e->getMessage()}");
            $this->record($lead, $driver, ['error' => $e->getMessage()]);

            return $driver;
        }

        if ($result === null) {
            return null;
        }

        $this->apply($lead, $result);
        $this->record($lead, $driver, $result->payload);

        return $driver;
    }

    private function apply(Lead $lead, EnrichmentResult $result): void
    {
        if ($result->email && ! $lead->email) {
            $lead->email = $result->email;
        }
        if ($result->phone && ! $lead->phone) {
            $lead->phone = $result->phone;
        }
        if ($result->whatsappValid !== null) {
            $lead->whatsapp_valid = $result->whatsappValid;
        }

        $lead->save();
    }

    /** @param  array<string, mixed>  $payload */
    private function record(Lead $lead, string $driver, array $payload): void
    {
        $lead->sources()->updateOrCreate(
            ['driver' => $driver, 'external_id' => $driver.':'.$lead->id],
            ['raw_payload' => $payload + ['at' => now()->toIso8601String()]],
        );
    }
}

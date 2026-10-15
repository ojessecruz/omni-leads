<?php

namespace App\Domains\Enrichment\Clients;

use App\Domains\Enrichment\Data\EnrichmentResult;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Step 2 (paid): Hunter.io domain-search. Only runs when the scrape found no e-mail.
 */
class HunterClient
{
    public const string ENDPOINT = 'https://api.hunter.io/v2/domain-search';

    public const string ACCOUNT_ENDPOINT = 'https://api.hunter.io/v2/account';

    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    /** @param  array<string, string>  $credentials  the user's key (BYOK) */
    public function withCredentials(array $credentials): self
    {
        return new self([...$this->config, ...$credentials]);
    }

    public function isConfigured(): bool
    {
        return filled($this->config['key'] ?? null);
    }

    /** Free call: fails when Hunter rejects the key. */
    public function verify(): void
    {
        $this->assertConfigured();

        $response = Http::acceptJson()->connectTimeout(3)->timeout(10)->get(self::ACCOUNT_ENDPOINT, ['api_key' => $this->config['key']]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Hunter responded %d: %s', $response->status(), $response->json('errors.0.details', $response->body())));
        }
    }

    /** @param  list<string>  $jobTitles  ICP job titles used to prioritize the contact */
    public function enrich(Lead $lead, array $jobTitles = []): ?EnrichmentResult
    {
        if (! $lead->domain) {
            return null;
        }

        $this->assertConfigured();
        $this->throttle();

        $response = Http::acceptJson()->connectTimeout(3)->timeout(15)->retry(2, 500, throw: false)->get(self::ENDPOINT, [
            'domain' => $lead->domain,
            'api_key' => $this->config['key'],
            'limit' => 10,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Hunter responded %d: %s', $response->status(), $response->json('errors.0.details', $response->body())));
        }

        $raw = $response->json('data.emails', []);

        /** @var Collection<int, array<string, mixed>> $emails */
        $emails = collect(is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [])
            ->filter(fn (array $e) => filled($e['value'] ?? null))
            ->sortByDesc(function (array $e) use ($jobTitles) {
                $position = mb_strtolower((string) ($e['position'] ?? ''));
                $matchesTitle = array_any($jobTitles, fn (string $t) => $t !== '' && str_contains($position, mb_strtolower($t)));

                return ($matchesTitle ? 1000 : 0) + (int) ($e['confidence'] ?? 0);
            })
            ->values();

        return new EnrichmentResult(
            driver: 'hunter',
            email: $emails->first()['value'] ?? null,
            payload: [
                'domain' => $lead->domain,
                'organization' => $response->json('data.organization'),
                'emails' => $emails->map(fn (array $e) => array_intersect_key($e, array_flip(['value', 'type', 'confidence', 'first_name', 'last_name', 'position', 'linkedin'])))->all(),
            ],
        );
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Hunter API key is not configured.');
        }
    }

    private function throttle(): void
    {
        $rpm = max(1, (int) ($this->config['requests_per_minute'] ?? 15));

        while (! RateLimiter::attempt('enrichment:hunter:'.md5((string) $this->config['key']), $rpm, fn () => true, 60)) {
            usleep(250_000);
        }
    }
}

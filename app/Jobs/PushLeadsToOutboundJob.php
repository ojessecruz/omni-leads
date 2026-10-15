<?php

namespace App\Jobs;

use App\Domains\Outbound\Data\OutboundProspect;
use App\Domains\Outbound\EmailTemplate;
use App\Domains\Outbound\Exceptions\OutboundException;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\OutboundConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends leads with an e-mail to the project's cold-mail campaign. A lead goes to a
 * connector only once: the lead_sources row is written before the push and a lead
 * that already has one is skipped, so re-runs and retries never import it twice.
 * Without $leadIds, every qualified lead of the project not sent yet.
 */
class PushLeadsToOutboundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param  list<int>|null  $leadIds */
    public function __construct(public int $connectionId, public ?array $leadIds = null)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $connection = OutboundConnection::find($this->connectionId);

        if ($connection === null || ! $connection->isReady()) {
            return;
        }

        $connector = $connection->connector();
        $driver = $connection->sourceDriver();
        $project = $connection->project;

        $this->pending($connection)->chunkById((int) config('outbound.push_chunk', 100), function (Collection $leads) use ($connection, $connector, $driver, $project) {
            /** @var Collection<int, Lead> $leads */
            $rows = $leads->mapWithKeys(fn (Lead $lead) => [$lead->id => $lead->sources()->firstOrCreate(
                ['driver' => $driver, 'external_id' => (string) $connection->campaign_id],
                ['raw_payload' => ['campaign' => $connection->campaign_name, 'queued_at' => now()->toIso8601String()]],
            )]);

            $prospects = $leads->mapWithKeys(function (Lead $lead) use ($project) {
                $email = EmailTemplate::render($project->email_subject_template, $project->email_template, $lead);

                return [$lead->id => OutboundProspect::fromLead($lead, $email['subject'], $email['body'])];
            });

            $requestId = $connector->push((string) $connection->campaign_id, array_values($prospects->all()));

            // The filled copy stays on the lead, so what was sent can be checked later.
            $rows->each(fn (LeadSource $row, int $leadId) => $row->update(['raw_payload' => [
                ...$row->raw_payload,
                'subject' => $prospects[$leadId]->subject,
                'message' => $prospects[$leadId]->message,
                'request_id' => $requestId,
                'pushed_at' => now()->toIso8601String(),
            ]]));
        });

        $connection->update(['last_error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        OutboundConnection::whereKey($this->connectionId)->update([
            'last_error' => $exception instanceof OutboundException ? $exception->getMessage() : 'Sending to the connector failed. Check the logs.',
        ]);
    }

    /** @return Builder<Lead> */
    private function pending(OutboundConnection $connection): Builder
    {
        return Lead::query()
            ->where('project_id', $connection->project_id)
            ->whereNotNull('email')
            ->when(
                $this->leadIds === null,
                fn (Builder $q) => $q->where('status', LeadStatus::Qualified),
                fn (Builder $q) => $q->whereKey($this->leadIds),
            )
            ->whereDoesntHave('sources', fn (Builder $q) => $q->where('driver', $connection->sourceDriver())->whereNotNull('raw_payload->pushed_at'));
    }
}

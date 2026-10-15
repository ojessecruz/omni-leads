<?php

namespace App\Jobs;

use App\Domains\Outbound\Data\OutboundProspect;
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

        $this->pending($connection)->chunkById((int) config('outbound.push_chunk', 100), function (Collection $leads) use ($connection, $connector, $driver) {
            /** @var Collection<int, Lead> $leads */
            $rows = $leads->mapWithKeys(fn (Lead $lead) => [$lead->id => $lead->sources()->firstOrCreate(
                ['driver' => $driver, 'external_id' => (string) $connection->campaign_id],
                ['raw_payload' => ['campaign' => $connection->campaign_name, 'queued_at' => now()->toIso8601String()]],
            )]);

            $requestId = $connector->push(
                (string) $connection->campaign_id,
                array_values($leads->map(fn (Lead $lead) => OutboundProspect::fromLead($lead))->all()),
            );

            $rows->each(fn (LeadSource $row) => $row->update(['raw_payload' => [
                ...$row->raw_payload,
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

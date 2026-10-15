<?php

namespace App\Jobs;

use App\Domains\Enrichment\LeadEnricher;
use App\Models\Lead;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

class EnrichLeadJob implements ShouldQueue
{
    use Queueable;

    /** Attempts are time-based (retryUntil) because RateLimited re-queues and each release counts as an attempt. */
    public int $maxExceptions = 2;

    public int $timeout = 120;

    public function __construct(public int $leadId, public bool $force = false)
    {
        $this->onQueue('enrichment');
    }

    /**
     * Throttled by the lowest RPM among the owner's connected paid clients (`enrichment` limiter in AppServiceProvider):
     * the job goes back to the queue instead of sleeping in the worker until the window opens.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('enrichment')];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(LeadEnricher $enricher): void
    {
        $lead = Lead::with('icp')->find($this->leadId);

        if ($lead === null) {
            return;
        }

        $ttl = (int) config('enrichment.ttl_days', 30);

        if (! $this->force && $lead->enriched_at !== null && $lead->enriched_at->gt(now()->subDays($ttl))) {
            ScoreLeadJob::dispatch($lead->id);

            return;
        }

        $enricher->enrich($lead);

        ScoreLeadJob::dispatch($lead->id);
    }
}

<?php

namespace App\Jobs;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/** Fan-out outside the request: walks the ICP leads in chunks and queues one EnrichLeadJob per lead. */
class EnrichIcpLeadsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $icpId, public bool $force = false)
    {
        $this->onQueue('enrichment');
    }

    public function handle(): void
    {
        Lead::query()
            ->inIcp($this->icpId)
            ->where('status', '!=', LeadStatus::Discarded)
            ->when(! $this->force, fn ($q) => $q->whereNull('enriched_at'))
            ->select('leads.id')
            ->chunkById(500, function (Collection $leads) {
                foreach ($leads as $lead) {
                    EnrichLeadJob::dispatch($lead->id, force: $this->force);
                }
            }, 'leads.id', 'id');
    }
}

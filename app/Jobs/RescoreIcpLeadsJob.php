<?php

namespace App\Jobs;

use App\Domains\Leads\LeadScorer;
use App\Models\Lead;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/** Rescores every lead of the ICP in a single job (scoring is local, no API). */
class RescoreIcpLeadsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $icpId)
    {
        $this->onQueue('scoring');
    }

    public function handle(LeadScorer $scorer): void
    {
        Lead::query()
            ->inIcp($this->icpId)
            ->with('icp')
            ->chunkById(200, function (Collection $leads) use ($scorer) {
                foreach ($leads as $lead) {
                    $scorer->score($lead);
                }
            }, 'leads.id', 'id');
    }
}

<?php

namespace App\Jobs;

use App\Domains\Leads\LeadScorer;
use App\Models\Lead;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScoreLeadJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $leadId)
    {
        $this->onQueue('scoring');
    }

    public function handle(LeadScorer $scorer): void
    {
        $lead = Lead::with('icp')->find($this->leadId);

        if ($lead !== null) {
            $scorer->score($lead);
        }
    }
}

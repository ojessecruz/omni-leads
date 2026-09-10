<?php

namespace App\Jobs;

use App\Domains\Leads\LeadMerger;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MergeDuplicateLeadsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public int $projectId)
    {
        $this->onQueue('scoring');
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(LeadMerger $merger): void
    {
        $project = Project::find($this->projectId);

        if ($project !== null) {
            $merger->mergeProject($project);
        }
    }
}

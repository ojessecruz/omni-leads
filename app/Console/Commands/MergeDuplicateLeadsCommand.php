<?php

namespace App\Console\Commands;

use App\Jobs\MergeDuplicateLeadsJob;
use App\Models\Project;
use Illuminate\Console\Command;

class MergeDuplicateLeadsCommand extends Command
{
    protected $signature = 'leads:merge-duplicates {project? : project id (all if omitted)}';

    protected $description = 'Merges duplicate leads (same tax id, domain, phone or email) within each project';

    public function handle(): int
    {
        $ids = $this->argument('project')
            ? [(int) $this->argument('project')]
            : Project::query()->pluck('id')->all();

        foreach ($ids as $id) {
            MergeDuplicateLeadsJob::dispatch((int) $id);
        }

        $this->info(count($ids).' project(s) queued for merge.');

        return self::SUCCESS;
    }
}

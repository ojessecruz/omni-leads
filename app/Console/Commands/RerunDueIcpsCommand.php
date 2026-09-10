<?php

namespace App\Console\Commands;

use App\Enums\IcpStatus;
use App\Enums\SearchJobStatus;
use App\Jobs\DispatchIcpSearchJob;
use App\Models\Icp;
use Illuminate\Console\Command;

class RerunDueIcpsCommand extends Command
{
    protected $signature = 'icps:rerun-due';

    protected $description = 'Re-runs the search of active ICPs whose re-run interval is due';

    public function handle(): int
    {
        $due = Icp::query()
            ->where('status', IcpStatus::Active)
            ->whereNotNull('rerun_every_days')
            ->where('next_run_at', '<=', now())
            ->whereDoesntHave('searchJobs', fn ($q) => $q->whereIn('status', [SearchJobStatus::Pending, SearchJobStatus::Running]))
            ->get();

        foreach ($due as $icp) {
            DispatchIcpSearchJob::dispatch($icp->id);
            $this->line("ICP #{$icp->id} ({$icp->name}) queued.");
        }

        $this->info($due->count().' ICP(s) re-run.');

        return self::SUCCESS;
    }
}

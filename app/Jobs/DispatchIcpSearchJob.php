<?php

namespace App\Jobs;

use App\Domains\LeadSources\LeadSourceManager;
use App\Enums\IcpStatus;
use App\Enums\SearchJobStatus;
use App\Models\Icp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * For each driver enabled for the project owner (keyless or with their key)
 * that supports the ICP, creates a search_job and queues the SourceSearchJob.
 */
class DispatchIcpSearchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $icpId) {}

    public function handle(LeadSourceManager $sources): void
    {
        $icp = Icp::with('project.user.integrations')->findOrFail($this->icpId);
        $sources = $sources->withCredentials($icp->project->user->integrationCredentials());
        $criteria = $icp->criteria_object;

        if ($icp->status !== IcpStatus::Active) {
            $icp->update(['status' => IcpStatus::Active]);
        }

        $icp->scheduleNextRun();

        foreach ($sources->enabledDrivers() as $name) {
            $driver = $sources->driver($name);

            if (! $driver->supports($criteria)) {
                continue;
            }

            $mapper = $driver->mapper();

            $searchJob = $icp->searchJobs()->create([
                'driver' => $driver->name(),
                'status' => SearchJobStatus::Pending,
                'params' => [
                    'queries' => $mapper->map($criteria),
                    'unsupported' => $mapper->unsupported($criteria),
                ],
            ]);

            SourceSearchJob::dispatch($searchJob->id);
        }
    }
}

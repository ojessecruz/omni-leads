<?php

namespace App\Jobs;

use App\Domains\Leads\LeadUpserter;
use App\Domains\LeadSources\Contracts\TracksRequests;
use App\Domains\LeadSources\LeadSourceManager;
use App\Enums\SearchJobStatus;
use App\Events\SearchJobFinished;
use App\Models\SearchJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SourceSearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $searchJobId)
    {
        $this->onQueue('discovery');
    }

    public function handle(LeadSourceManager $sources, LeadUpserter $upserter): void
    {
        $searchJob = SearchJob::with('icp.project.user.integrations')->findOrFail($this->searchJobId);

        // Idempotency: only process while still pending.
        if (! $searchJob->isPending()) {
            return;
        }

        $searchJob->update(['status' => SearchJobStatus::Running, 'started_at' => now()]);

        $count = 0;

        try {
            $driver = $sources->withCredentials($searchJob->icp->project->user->integrationCredentials())->driver($searchJob->driver);

            $criteria = $searchJob->icp->criteria_object;

            foreach ($driver->search($searchJob->params['queries'] ?? []) as $dto) {
                // Sources resolve ambiguous places on their own: never keep a lead from another country.
                if (! $criteria->acceptsCountry($dto->country)) {
                    continue;
                }

                $lead = $upserter->upsert($searchJob->icp, $dto);
                EnrichLeadJob::dispatch($lead->id);
                $count++;

                if ($count % 20 === 0) {
                    $searchJob->update(['results_count' => $count, 'requests_count' => $this->requests($driver)]);
                }
            }

            $searchJob->update([
                'status' => SearchJobStatus::Done,
                'results_count' => $count,
                'requests_count' => $this->requests($driver),
                'finished_at' => now(),
            ]);

            MergeDuplicateLeadsJob::dispatch($searchJob->icp->project_id);
            SearchJobFinished::dispatch($searchJob);
        } catch (Throwable $e) {
            $searchJob->update([
                'status' => SearchJobStatus::Failed,
                'results_count' => $count,
                'requests_count' => isset($driver) ? $this->requests($driver) : 0,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            SearchJobFinished::dispatch($searchJob);

            throw $e;
        }
    }

    private function requests(object $driver): int
    {
        return $driver instanceof TracksRequests ? $driver->requestCount() : 0;
    }
}

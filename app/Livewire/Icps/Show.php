<?php

namespace App\Livewire\Icps;

use App\Domains\Leads\IcpStats;
use App\Enums\LeadStatus;
use App\Enums\SearchJobStatus;
use App\Jobs\DispatchIcpSearchJob;
use App\Jobs\EnrichIcpLeadsJob;
use App\Jobs\RescoreIcpLeadsJob;
use App\Models\Icp;
use App\Models\Lead;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    public Icp $icp;

    public function mount(Icp $icp): void
    {
        Gate::authorize('view', $icp->project);

        $this->icp = $icp;
    }

    public function runSearch(): void
    {
        Gate::authorize('update', $this->icp->project);

        if ($this->hasActiveSearch()) {
            Flux::toast(variant: 'warning', text: __('A search is already running for this ICP.'));

            return;
        }

        DispatchIcpSearchJob::dispatch($this->icp->id);

        Flux::toast(variant: 'success', text: __('Search queued. Leads show up as they arrive.'));
    }

    public function enrichAll(bool $force = false): void
    {
        Gate::authorize('update', $this->icp->project);

        $count = Lead::query()
            ->inIcp($this->icp->id)
            ->where('status', '!=', LeadStatus::Discarded)
            ->when(! $force, fn ($q) => $q->whereNull('enriched_at'))
            ->count();

        EnrichIcpLeadsJob::dispatch($this->icp->id, force: $force);

        Flux::toast(text: __(':count leads sent for enrichment.', ['count' => $count]));
    }

    public function rescoreAll(): void
    {
        Gate::authorize('update', $this->icp->project);

        $count = Lead::query()->inIcp($this->icp->id)->count();

        RescoreIcpLeadsJob::dispatch($this->icp->id);

        Flux::toast(text: __('Scores of :count leads will be recalculated.', ['count' => $count]));
    }

    public function setRerun(string $days): void
    {
        Gate::authorize('update', $this->icp->project);

        Validator::make(['days' => $days], ['days' => ['nullable', Rule::in(Icp::RERUN_INTERVALS)]])->validate();

        $days = $days === '' ? null : (int) $days;

        $this->icp->forceFill([
            'rerun_every_days' => $days,
            'next_run_at' => $days ? ($this->icp->last_run_at ?? now())->addDays($days) : null,
        ])->save();

        Flux::toast(text: $days ? __('Search will re-run every :days days.', ['days' => $days]) : __('Automatic re-run turned off.'));
    }

    protected function hasActiveSearch(): bool
    {
        return $this->icp->searchJobs()
            ->whereIn('status', [SearchJobStatus::Pending, SearchJobStatus::Running])
            ->exists();
    }

    public function render(IcpStats $stats): View
    {
        $searchJobs = $this->icp->searchJobs()->latest()->limit(20)->get();
        $leads = fn () => Lead::query()->inIcp($this->icp->id);
        $byCountry = $leads()
            ->selectRaw('country, count(*) as total')
            ->groupBy('country')
            ->orderByDesc('total')
            ->limit(6)
            ->pluck('total', 'country');

        /** @var object{discovered: int, enriched: int, qualified: int, contacted: int} $totals */
        $totals = $leads()->selectRaw(
            'count(*) as discovered, count(enriched_at) as enriched, count(*) filter (where status = ?) as qualified, count(*) filter (where status in (?, ?)) as contacted',
            [LeadStatus::Qualified->value, LeadStatus::Contacted->value, LeadStatus::Replied->value],
        )->toBase()->first();

        $funnel = [
            'discovered' => (int) $totals->discovered,
            'enriched' => (int) $totals->enriched,
            'qualified' => (int) $totals->qualified,
            'contacted' => (int) $totals->contacted,
        ];
        $leadsCount = $funnel['discovered'];

        return view('livewire.icps.show', [
            'funnel' => $funnel,
            'bySource' => $stats->leadsBySource($this->icp),
            'cost' => $stats->estimatedCost($this->icp),
            'criteria' => $this->icp->criteria_object,
            'searchJobs' => $searchJobs,
            'leadsCount' => $leadsCount,
            'byCountry' => $byCountry,
            'active' => $this->hasActiveSearch(),
        ])->title($this->icp->name);
    }
}

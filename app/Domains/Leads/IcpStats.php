<?php

namespace App\Domains\Leads;

use App\Models\Icp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\SearchJob;
use Illuminate\Support\Collection;

/** ICP statistics: leads per source and estimated API cost. */
class IcpStats
{
    /** @return Collection<string, int> driver => leads */
    public function leadsBySource(Icp $icp): Collection
    {
        return LeadSource::query()
            ->whereIn('lead_id', Lead::query()->inIcp($icp->id)->select('leads.id'))
            ->whereIn('driver', array_keys(config('lead-sources.drivers', [])))
            ->selectRaw('driver, count(distinct lead_id) as total')
            ->groupBy('driver')
            ->orderByDesc('total')
            ->pluck('total', 'driver')
            ->map(fn ($v) => (int) $v);
    }

    /**
     * @return array{total: float, items: list<array{source: string, requests: int, unit: float, cost: float}>}
     */
    public function estimatedCost(Icp $icp): array
    {
        $items = [];

        $searches = SearchJob::query()
            ->where('icp_id', $icp->id)
            ->selectRaw('driver, sum(requests_count) as requests')
            ->groupBy('driver')
            ->pluck('requests', 'driver');

        foreach ($searches as $driver => $requests) {
            $items[] = $this->item($driver, (int) $requests, (float) config("lead-sources.drivers.{$driver}.cost_per_request", 0));
        }

        $enrichment = LeadSource::query()
            ->whereIn('lead_id', Lead::query()->inIcp($icp->id)->select('leads.id'))
            ->whereIn('driver', ['hunter', 'evolution'])
            ->selectRaw('driver, count(*) as requests')
            ->groupBy('driver')
            ->pluck('requests', 'driver');

        foreach ($enrichment as $driver => $requests) {
            $items[] = $this->item($driver, (int) $requests, (float) config("enrichment.{$driver}.cost_per_request", 0));
        }

        return [
            'total' => round(array_sum(array_column($items, 'cost')), 2),
            'items' => $items,
        ];
    }

    /** @return array{source: string, requests: int, unit: float, cost: float} */
    private function item(string $source, int $requests, float $unit): array
    {
        return ['source' => $source, 'requests' => $requests, 'unit' => $unit, 'cost' => round($requests * $unit, 2)];
    }
}

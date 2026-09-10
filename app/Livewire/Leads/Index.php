<?php

namespace App\Livewire\Leads;

use App\Domains\Leads\LeadCsvExporter;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\LeadSource;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Leads')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $icp = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $minScore = 0;

    #[Url]
    public string $country = '';

    #[Url]
    public string $driver = '';

    /** @var array<int, int> */
    public array $selected = [];

    public bool $selectAll = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'icp', 'status', 'minScore', 'country', 'driver'], true)) {
            $this->resetPage();
            $this->reset('selected', 'selectAll');
        }
    }

    /** Selects only the current page: the selection travels in the Livewire payload and actions are per lead. */
    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->pageQuery()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    /** Bulk status change. Qualified is owned by the scorer, so only contacted/discarded are allowed here. */
    public function markAs(string $status): void
    {
        Validator::make(
            ['status' => $status],
            ['status' => [Rule::enum(LeadStatus::class)->only([LeadStatus::Contacted, LeadStatus::Discarded])]],
        )->validate();

        $status = LeadStatus::from($status);

        $leads = $this->query()->whereIn('id', $this->selected)->where('status', '!=', $status)->get();
        $count = 0;

        foreach ($leads as $lead) {
            $from = $lead->status;
            $lead->update(['status' => $status]);
            LeadStatusChanged::dispatch($lead, $from);
            $count++;
        }

        $this->reset('selected', 'selectAll');

        Flux::toast(text: trans_choice(':count lead marked as :status|:count leads marked as :status', $count, ['status' => $status->label()]));
    }

    public function export(LeadCsvExporter $exporter): StreamedResponse
    {
        $query = $this->query();

        if ($this->selected !== []) {
            $query->whereIn('id', $this->selected);
        }

        $filename = 'leads-'.now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($exporter, $query) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            $exporter->write($query, $handle);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'icp', 'status', 'minScore', 'country', 'driver', 'selected', 'selectAll');
        $this->resetPage();
    }

    public const int PER_PAGE = 25;

    /** @return Builder<Lead> */
    protected function pageQuery(): Builder
    {
        return $this->query()
            ->orderByDesc('score')
            ->latest('id')
            ->forPage($this->getPage(), self::PER_PAGE);
    }

    /** @return Builder<Lead> */
    protected function query(): Builder
    {
        return Lead::query()
            ->whereHas('project', fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->when($this->icp !== '', fn (Builder $q) => $q->inIcp((int) $this->icp))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->minScore > 0, fn (Builder $q) => $q->where('score', '>=', $this->minScore))
            ->when($this->country !== '', fn (Builder $q) => $q->where('country', strtoupper($this->country)))
            ->when($this->driver !== '', fn (Builder $q) => $q->fromDriver($this->driver))
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('lower(coalesce(company_name, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(name, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(domain, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(email, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(city, \'\')) like ?', [$term]));
            });
    }

    public function render(): View
    {
        $base = Lead::query()->whereHas('project', fn (Builder $q) => $q->where('user_id', auth()->id()));

        $icps = Icp::query()
            ->whereHas('project', fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name']);

        $countries = (clone $base)->distinct()->orderBy('country')->pluck('country');
        $drivers = LeadSource::query()
            ->whereIn('lead_id', (clone $base)->select('id'))
            ->distinct()
            ->orderBy('driver')
            ->pluck('driver');

        $leads = $this->query()
            ->with(['icp:id,name', 'sources:id,lead_id,driver'])
            ->orderByDesc('score')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.leads.index', [
            'leads' => $leads,
            'icps' => $icps,
            'countries' => $countries,
            'drivers' => $drivers,
            'statuses' => LeadStatus::cases(),
        ]);
    }
}

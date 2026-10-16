<?php

namespace App\Livewire\Leads;

use App\Actions\WhatsApp\QueueFirstContact;
use App\Domains\Leads\LeadCsvExporter;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\LeadSource;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
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

    #[Url]
    public string $city = '';

    /** score | recent | name */
    #[Url(except: 'score')]
    public string $sort = 'score';

    /** @var array<int, int> */
    public array $selected = [];

    /** Lead in the "Send WhatsApp" modal. */
    public ?int $whatsappLeadId = null;

    public string $whatsappText = '';

    public bool $selectAll = false;

    public function updated(string $property): void
    {
        if ($property === 'sort' && ! in_array($this->sort, ['score', 'recent', 'name'], true)) {
            $this->sort = 'score';
        }

        if (in_array($property, ['search', 'icp', 'status', 'minScore', 'country', 'driver', 'city', 'sort'], true)) {
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
        $status = $this->manualStatus($status);
        $count = $this->changeStatus($this->selected, $status);

        $this->reset('selected', 'selectAll');

        Flux::toast(text: trans_choice(':count lead marked as :status|:count leads marked as :status', $count, ['status' => $status->label()]));
    }

    /** Row action: same rules as the bulk one, without touching the selection. */
    public function markOne(int $leadId, string $status): void
    {
        $status = $this->manualStatus($status);
        $count = $this->changeStatus([$leadId], $status);

        Flux::toast(text: trans_choice(':count lead marked as :status|:count leads marked as :status', $count, ['status' => $status->label()]));
    }

    /** Opens the first-contact modal with the project's template filled for this lead. */
    public function openWhatsApp(int $leadId): void
    {
        $lead = $this->query()->with('project')->findOrFail($leadId);

        $this->resetErrorBag('whatsappText');
        $this->whatsappLeadId = $lead->id;
        $this->whatsappText = WhatsAppTemplate::render($lead->project->whatsapp_template, $lead);

        Flux::modal('lead-whatsapp')->show();
    }

    public function sendWhatsApp(QueueFirstContact $queue): void
    {
        $this->validate(['whatsappText' => ['required', 'string', 'max:2000']]);

        $lead = $this->query()->with('project.user')->findOrFail($this->whatsappLeadId);

        // A lead with a conversation is answered from the inbox, not with a new first message.
        if ($lead->whatsappConversations()->where('user_id', $lead->project->user_id)->exists()) {
            $this->addError('whatsappText', __('This lead already has a conversation. Reply from the inbox.'));

            return;
        }

        try {
            $queue($lead, $this->whatsappText);
        } catch (RuntimeException $e) {
            $this->addError('whatsappText', $e->getMessage());

            return;
        }

        $this->reset('whatsappLeadId', 'whatsappText');
        Flux::modal('lead-whatsapp')->close();
        Flux::toast(variant: 'success', text: __('Message queued. The lead will be marked as contacted after sending.'));
    }

    /** The send job stored the message and marked the lead contacted: redraw the row. */
    #[On('whatsapp-message')]
    public function onWhatsAppMessage(): void {}

    protected function manualStatus(string $status): LeadStatus
    {
        Validator::make(
            ['status' => $status],
            ['status' => [Rule::enum(LeadStatus::class)->only([LeadStatus::Contacted, LeadStatus::Discarded])]],
        )->validate();

        return LeadStatus::from($status);
    }

    /** @param  array<int, int>  $ids */
    protected function changeStatus(array $ids, LeadStatus $status): int
    {
        $leads = $this->query()->whereIn('id', $ids)->where('status', '!=', $status)->get();

        foreach ($leads as $lead) {
            $from = $lead->status;
            $lead->update(['status' => $status]);
            LeadStatusChanged::dispatch($lead, $from);
        }

        return $leads->count();
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
        $this->reset('search', 'icp', 'status', 'minScore', 'country', 'driver', 'city', 'selected', 'selectAll');
        $this->resetPage();
    }

    public const int PER_PAGE = 25;

    /** @return Builder<Lead> */
    protected function pageQuery(): Builder
    {
        return $this->sorted($this->query())->forPage($this->getPage(), self::PER_PAGE);
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    protected function sorted(Builder $query): Builder
    {
        return match ($this->sort) {
            'recent' => $query->latest('created_at')->latest('id'),
            'name' => $query->orderByRaw('lower(coalesce(company_name, name, \'\'))')->orderBy('id'),
            default => $query->orderByDesc('score')->latest('id'),
        };
    }

    /**
     * Leads matching the filters. $except leaves some out, so each filter can count
     * its options against all the others (facets).
     *
     * @param  list<'icp'|'status'|'city'|'driver'>  $except
     * @return Builder<Lead>
     */
    protected function query(array $except = []): Builder
    {
        $on = fn (string $filter, string $value) => $value !== '' && ! in_array($filter, $except, true);

        return Lead::query()
            ->whereHas('project', fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->when($on('icp', $this->icp), fn (Builder $q) => $q->inIcp((int) $this->icp))
            ->when($on('status', $this->status), fn (Builder $q) => $q->where('status', $this->status))
            ->when($on('city', $this->city), fn (Builder $q) => $q->where('city', $this->city))
            ->when($this->minScore > 0, fn (Builder $q) => $q->where('score', '>=', $this->minScore))
            ->when($this->country !== '', fn (Builder $q) => $q->where('country', strtoupper($this->country)))
            ->when($on('driver', $this->driver), fn (Builder $q) => $q->fromDriver($this->driver))
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

        $icpCounts = DB::table('icp_lead')
            ->whereIn('lead_id', $this->query(['icp'])->select('id'))
            ->groupBy('icp_id')
            ->selectRaw('icp_id, count(*) as total')
            ->pluck('total', 'icp_id');

        $icps = Icp::query()
            ->whereHas('project', fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->with('project:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'project_id']);

        // Same ICP name in two projects would read as a duplicate: name the project then.
        $manyProjects = $icps->pluck('project_id')->unique()->count() > 1;
        $icps = $icps->mapWithKeys(fn (Icp $i) => [$i->id => [
            'label' => $i->name,
            'hint' => $manyProjects ? $i->project->name : null,
            'count' => (int) ($icpCounts[$i->id] ?? 0),
        ]]);

        // Only cities with leads under the other filters, plus the selected one.
        $cities = $this->query(['city'])->toBase()
            ->whereNotNull('city')
            ->groupBy('city')
            ->orderBy('city')
            ->selectRaw('city, count(*) as total')
            ->pluck('total', 'city')
            ->map(fn ($count, $city) => ['label' => $city, 'count' => (int) $count]);

        if ($this->city !== '' && ! $cities->has($this->city)) {
            $cities->put($this->city, ['label' => $this->city, 'count' => 0]);
        }

        $drivers = LeadSource::query()
            ->whereIn('lead_id', $this->query(['driver'])->select('id'))
            ->groupBy('driver')
            ->orderBy('driver')
            ->selectRaw('driver, count(distinct lead_id) as total')
            ->pluck('total', 'driver')
            ->map(fn ($count, $driver) => ['label' => $driver, 'count' => (int) $count]);

        $countries = (clone $base)->distinct()->orderBy('country')->pluck('country');

        // Tab counters follow every filter but the status itself.
        $statusCounts = $this->query(['status'])->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $leads = $this->sorted($this->query())
            ->with(['icp:id,name', 'sources:id,lead_id,driver', 'whatsappConversations:id'])
            ->paginate(self::PER_PAGE);

        return view('livewire.leads.index', [
            'leads' => $leads,
            'icps' => $icps,
            'countries' => $countries,
            'cities' => $cities,
            'statusCounts' => $statusCounts,
            'qualifiedAt' => (int) config('lead-scoring.qualified_threshold', 60),
            'canWhatsApp' => auth()->user()->hasIntegration(IntegrationProvider::Evolution),
            'whatsappLead' => $this->whatsappLeadId ? $this->query()->find($this->whatsappLeadId) : null,
            'drivers' => $drivers,
            // Funnel order for the tabs.
            'statuses' => [LeadStatus::New, LeadStatus::Qualified, LeadStatus::Contacted, LeadStatus::Replied, LeadStatus::Discarded],
        ]);
    }
}

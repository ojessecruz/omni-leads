<?php

namespace App\Livewire\Leads;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Leads\LeadScorer;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Jobs\EnrichLeadJob;
use App\Jobs\SendWhatsAppJob;
use App\Models\Lead;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    public Lead $lead;

    public string $whatsappText = '';

    public function mount(Lead $lead): void
    {
        Gate::authorize('view', $lead->project);

        $this->lead = $lead;
        $this->whatsappText = WhatsAppTemplate::render($lead->project->whatsapp_template, $lead);
    }

    public function sendWhatsApp(): void
    {
        Gate::authorize('update', $this->lead->project);

        $this->validate(['whatsappText' => ['required', 'string', 'max:2000']]);

        if (! $this->lead->phone) {
            Flux::toast(variant: 'danger', text: __('Lead has no phone.'));

            return;
        }

        SendWhatsAppJob::dispatch($this->lead->id, $this->whatsappText);

        Flux::modal('whatsapp')->close();
        Flux::toast(variant: 'success', text: __('Message queued. The lead will be marked as contacted after sending.'));
    }

    public function setStatus(string $status): void
    {
        Gate::authorize('update', $this->lead->project);

        Validator::make(['status' => $status], ['status' => [Rule::enum(LeadStatus::class)]])->validate();

        $from = $this->lead->status;
        $this->lead->update(['status' => LeadStatus::from($status)]);

        if ($from !== $this->lead->status) {
            LeadStatusChanged::dispatch($this->lead, $from);
        }

        Flux::toast(text: __('Status updated to :status.', ['status' => $this->lead->status->label()]));
    }

    public function enrich(): void
    {
        Gate::authorize('update', $this->lead->project);

        EnrichLeadJob::dispatch($this->lead->id, force: true);

        Flux::toast(text: __('Enrichment queued. Refresh in a moment.'));
    }

    public function render(LeadScorer $scorer): View
    {
        return view('livewire.leads.show', [
            'sources' => $this->lead->sources()->latest()->get(),
            'statuses' => LeadStatus::cases(),
            'scoring' => $scorer->evaluate($this->lead),
            'canWhatsApp' => (bool) config('enrichment.evolution.enabled') && app(EvolutionClient::class)->isConfigured() && $this->lead->phone,
        ])->title($this->lead->displayName());
    }
}

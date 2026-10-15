<?php

namespace App\Livewire\Leads;

use App\Domains\Leads\LeadScorer;
use App\Domains\Outbound\OutboundConnectorResolver;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Jobs\EnrichLeadJob;
use App\Jobs\PushLeadsToOutboundJob;
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

    public function pushToOutbound(): void
    {
        Gate::authorize('update', $this->lead->project);

        $connection = $this->lead->project->outboundConnection;

        if ($connection === null || ! $connection->isReady() || ! $this->lead->email) {
            Flux::toast(variant: 'danger', text: __('This lead cannot be sent: it needs an email and a connector with a campaign.'));

            return;
        }

        PushLeadsToOutboundJob::dispatch($connection->id, [$this->lead->id]);

        Flux::toast(variant: 'success', text: __('Lead queued for :campaign.', ['campaign' => $connection->campaign_name ?? $connection->campaign_id]));
    }

    public function enrich(): void
    {
        Gate::authorize('update', $this->lead->project);

        EnrichLeadJob::dispatch($this->lead->id, force: true);

        Flux::toast(text: __('Enrichment queued. Refresh in a moment.'));
    }

    public function render(LeadScorer $scorer, OutboundConnectorResolver $resolver): View
    {
        $outbound = $this->lead->project->outboundConnection;
        $sources = $this->lead->sources()->latest()->get();

        return view('livewire.leads.show', [
            'sources' => $sources,
            'outbound' => $outbound?->isReady() && $this->lead->email ? $outbound : null,
            'outboundLabel' => $outbound ? $resolver->label($outbound->connector) : null,
            'outboundSent' => $outbound !== null && $sources->contains(fn ($s) => $s->driver === $outbound->sourceDriver() && isset($s->raw_payload['pushed_at'])),
            'statuses' => LeadStatus::cases(),
            'scoring' => $scorer->evaluate($this->lead),
            'canWhatsApp' => $this->lead->phone && $this->lead->project->user->hasIntegration(IntegrationProvider::Evolution),
        ])->title($this->lead->displayName());
    }
}

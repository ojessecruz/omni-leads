<?php

namespace App\Livewire\Leads;

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\WhatsApp\QueueFirstContact;
use App\Domains\Leads\LeadScorer;
use App\Domains\Outbound\OutboundConnectorResolver;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Jobs\EnrichLeadJob;
use App\Jobs\PushLeadsToOutboundJob;
use App\Models\Lead;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

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

    public function sendWhatsApp(QueueFirstContact $queue): void
    {
        Gate::authorize('update', $this->lead->project);

        $this->validate(['whatsappText' => ['required', 'string', 'max:2000']]);

        // The modal only exists while the lead has no conversation: this is always a first contact.
        try {
            $at = $queue($this->lead, $this->whatsappText);
        } catch (RuntimeException $e) {
            $this->addError('whatsappText', $e->getMessage());

            return;
        }

        Flux::modal('whatsapp')->close();
        Flux::toast(variant: 'success', text: QueueFirstContact::feedback($at));
    }

    public function setStatus(string $status): void
    {
        Gate::authorize('update', $this->lead->project);

        Validator::make(['status' => $status], ['status' => [Rule::enum(LeadStatus::class)]])->validate();

        app(ChangeLeadStatus::class)($this->lead, LeadStatus::from($status));

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

    /**
     * A WhatsApp message to or from this lead's number: reload the lead (status) and,
     * after the first message, show the conversation that now exists.
     *
     * @param  array<string, mixed>  $payload  WhatsAppMessageStored::broadcastWith()
     */
    #[On('whatsapp-message')]
    public function onWhatsAppMessage(array $payload): void
    {
        if ($this->lead->whatsappConversations()->whereKey($payload['conversation_id'] ?? 0)->exists()) {
            $this->lead->refresh();
        } else {
            $this->skipRender();
        }
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
            'conversation' => $this->lead->whatsappConversations()->where('user_id', $this->lead->project->user_id)->first(),
            'scheduledWhatsApp' => $this->lead->pendingWhatsAppSends()->latest('id')->value('raw_payload')['scheduled_for'] ?? null,
        ])->title($this->lead->displayName());
    }
}

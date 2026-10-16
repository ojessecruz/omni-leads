<?php

namespace App\Livewire\Projects;

use App\Domains\Outbound\EmailTemplate;
use App\Domains\Outbound\Exceptions\OutboundException;
use App\Domains\Outbound\OutboundConnectorResolver;
use App\Domains\Outbound\WhatsAppTemplate;
use App\Enums\IntegrationProvider;
use App\Jobs\MergeDuplicateLeadsJob;
use App\Jobs\PushLeadsToOutboundJob;
use App\Listeners\DispatchWebhooks;
use App\Models\OutboundConnection;
use App\Models\Project;
use App\Models\Webhook;
use App\Support\LocalUrl;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Project settings')]
class Settings extends Component
{
    public Project $project;

    public string $whatsappTemplate = '';

    public string $webhookUrl = '';

    /** @var list<string> */
    public array $webhookEvents = ['lead.qualified'];

    public ?string $revealedSecret = null;

    public string $emailSubject = '';

    public string $emailBody = '';

    /** '' = no connector. The key itself lives in the owner's integrations. */
    public string $connector = '';

    public string $campaignId = '';

    public bool $autoPush = false;

    /** @var list<array{id: string, name: string, active: bool}> */
    public array $campaigns = [];

    public ?string $campaignsError = null;

    public function mount(Project $project): void
    {
        Gate::authorize('update', $project);

        $this->project = $project;
        $this->whatsappTemplate = $project->whatsapp_template ?? WhatsAppTemplate::default();
        $this->emailSubject = $project->email_subject_template ?? EmailTemplate::defaultSubject();
        $this->emailBody = $project->email_template ?? EmailTemplate::defaultBody();

        if ($connection = $project->outboundConnection) {
            $this->connector = $connection->connector;
            $this->campaignId = (string) $connection->campaign_id;
            $this->autoPush = $connection->auto_push;
            $this->loadCampaigns($connection);
        }
    }

    public function updatedConnector(): void
    {
        Gate::authorize('update', $this->project);

        $resolver = app(OutboundConnectorResolver::class);

        $this->validate(['connector' => ['nullable', Rule::in(array_keys($resolver->options()))]]);

        if ($this->connector === '') {
            $this->disconnectOutbound();

            return;
        }

        if (! $this->project->user->hasIntegration(IntegrationProvider::from($this->connector))) {
            $this->addError('connector', __('Add your :connector API key in Settings > Integrations first.', ['connector' => $resolver->label($this->connector)]));
            $this->connector = (string) $this->project->outboundConnection?->connector;

            return;
        }

        $connection = $this->project->outboundConnection()->updateOrCreate([], [
            'connector' => $this->connector,
            'campaign_id' => null,
            'campaign_name' => null,
            'webhook_id' => null,
            'webhook_secret' => Str::random(40),
            'last_error' => null,
        ]);

        $this->reset('campaignId');
        $this->project->setRelation('outboundConnection', $connection);
        $this->loadCampaigns($connection);
        $this->registerWebhook($connection);

        Flux::toast(variant: 'success', text: __(':connector connected. Now pick the campaign.', ['connector' => $resolver->label($this->connector)]));
    }

    public function saveOutbound(): void
    {
        Gate::authorize('update', $this->project);

        $connection = $this->project->outboundConnection()->firstOrFail();

        $this->validate([
            'campaignId' => ['required', 'string', Rule::in(array_column($this->campaigns, 'id'))],
            'autoPush' => ['boolean'],
        ]);

        $campaign = collect($this->campaigns)->firstWhere('id', $this->campaignId);

        $connection->update([
            'campaign_id' => $this->campaignId,
            'campaign_name' => $campaign['name'] ?? null,
            'auto_push' => $this->autoPush,
        ]);

        Flux::toast(variant: 'success', text: __('Outbound settings saved.'));
    }

    public function refreshCampaigns(): void
    {
        Gate::authorize('update', $this->project);

        $this->loadCampaigns($this->project->outboundConnection()->firstOrFail());
    }

    public function registerOutboundWebhook(): void
    {
        Gate::authorize('update', $this->project);

        $connection = $this->project->outboundConnection()->firstOrFail();

        if ($this->registerWebhook($connection)) {
            Flux::toast(variant: 'success', text: __('Webhook registered.'));
        }
    }

    public function pushQualifiedLeads(): void
    {
        Gate::authorize('update', $this->project);

        $connection = $this->project->outboundConnection()->firstOrFail();

        if (! $connection->isReady()) {
            Flux::toast(variant: 'danger', text: __('Pick a campaign first.'));

            return;
        }

        PushLeadsToOutboundJob::dispatch($connection->id);

        Flux::toast(text: __('Qualified leads with an email were queued for sending.'));
    }

    protected function disconnectOutbound(): void
    {
        $this->project->outboundConnection()->delete();
        $this->project->unsetRelation('outboundConnection');
        $this->reset('connector', 'campaignId', 'autoPush', 'campaigns', 'campaignsError');

        Flux::toast(text: __('Connector removed. Delete the Omni Leads webhook in the tool too.'));
    }

    public function saveTemplate(): void
    {
        Gate::authorize('update', $this->project);

        $this->validate(['whatsappTemplate' => ['required', 'string', 'max:2000']]);

        $this->project->update(['whatsapp_template' => $this->whatsappTemplate]);

        Flux::toast(variant: 'success', text: __('Template saved.'));
    }

    public function saveEmailTemplate(): void
    {
        Gate::authorize('update', $this->project);

        $this->validate([
            'emailSubject' => ['required', 'string', 'max:200'],
            'emailBody' => ['required', 'string', 'max:5000'],
        ]);

        $this->project->update(['email_subject_template' => $this->emailSubject, 'email_template' => $this->emailBody]);

        Flux::toast(variant: 'success', text: __('Template saved.'));
    }

    public function addWebhook(): void
    {
        Gate::authorize('update', $this->project);

        $this->validate([
            'webhookUrl' => ['required', 'url:http,https', 'max:2048'],
            'webhookEvents' => ['array', 'min:1'],
            'webhookEvents.*' => ['in:'.implode(',', array_keys(Webhook::EVENTS))],
        ]);

        $secret = Str::random(40);

        $this->project->webhooks()->create([
            'url' => $this->webhookUrl,
            'secret' => $secret,
            'events' => $this->webhookEvents,
        ]);

        $this->revealedSecret = $secret;
        $this->reset('webhookUrl');
        $this->webhookEvents = ['lead.qualified'];

        Flux::toast(variant: 'success', text: __('Webhook created. Copy the secret now, it will not be shown again.'));
    }

    public function toggleWebhook(int $id): void
    {
        Gate::authorize('update', $this->project);

        $hook = $this->project->webhooks()->findOrFail($id);
        $hook->update(['active' => ! $hook->active, 'failures_count' => 0]);
    }

    public function deleteWebhook(int $id): void
    {
        Gate::authorize('update', $this->project);

        $this->project->webhooks()->findOrFail($id)->delete();

        Flux::toast(text: __('Webhook removed.'));
    }

    public function testWebhook(int $id): void
    {
        Gate::authorize('update', $this->project);

        $hook = $this->project->webhooks()->findOrFail($id);

        app(DispatchWebhooks::class)->test($this->project, $hook);

        Flux::toast(text: __('Test event queued.'));
    }

    public function mergeDuplicates(): void
    {
        Gate::authorize('update', $this->project);

        MergeDuplicateLeadsJob::dispatch($this->project->id);

        Flux::toast(text: __('Duplicate merge queued.'));
    }

    protected function loadCampaigns(OutboundConnection $connection): void
    {
        try {
            $this->campaigns = array_map(fn ($c) => $c->toArray(), $connection->connector()->campaigns());
            $this->campaignsError = null;
        } catch (OutboundException $e) {
            $this->campaigns = [];
            $this->campaignsError = $e->getMessage();
        }
    }

    /** Local URLs are never reachable by the tool, so the webhook is not even attempted. */
    protected function registerWebhook(OutboundConnection $connection): bool
    {
        $url = route('outbound.webhook', [$connection, $connection->webhook_secret]);

        if (LocalUrl::is($url)) {
            return false;
        }

        try {
            $connection->update(['webhook_id' => $connection->connector()->registerWebhook($url), 'last_error' => null]);

            return true;
        } catch (OutboundException $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', text: __('Could not register the webhook: :reason', ['reason' => $e->getMessage()]));

            return false;
        }
    }

    public function render(OutboundConnectorResolver $resolver): View
    {
        $outbound = $this->project->outboundConnection;

        return view('livewire.projects.settings', [
            'outbound' => $outbound,
            'connectors' => $resolver->options(),
            'connectorKeys' => array_filter(array_keys($resolver->options()), fn (string $c) => $this->project->user->hasIntegration(IntegrationProvider::from($c))),
            'outboundLabel' => $outbound ? $resolver->label($outbound->connector) : null,
            'messageTags' => $outbound ? $resolver->resolve($outbound->connector, [])->messageTags() : null,
            'webhookIsLocal' => $outbound ? LocalUrl::is(route('outbound.webhook', [$outbound, $outbound->webhook_secret])) : false,
            'webhooks' => $this->project->webhooks()->latest()->get(),
            'events' => Webhook::labels(),
            'placeholders' => WhatsAppTemplate::PLACEHOLDERS,
        ]);
    }
}

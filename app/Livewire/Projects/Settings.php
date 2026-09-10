<?php

namespace App\Livewire\Projects;

use App\Domains\Outbound\WhatsAppTemplate;
use App\Jobs\MergeDuplicateLeadsJob;
use App\Listeners\DispatchWebhooks;
use App\Models\Project;
use App\Models\Webhook;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

    public function mount(Project $project): void
    {
        Gate::authorize('update', $project);

        $this->project = $project;
        $this->whatsappTemplate = $project->whatsapp_template ?? WhatsAppTemplate::default();
    }

    public function saveTemplate(): void
    {
        Gate::authorize('update', $this->project);

        $this->validate(['whatsappTemplate' => ['required', 'string', 'max:2000']]);

        $this->project->update(['whatsapp_template' => $this->whatsappTemplate]);

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

    public function render(): View
    {
        return view('livewire.projects.settings', [
            'webhooks' => $this->project->webhooks()->latest()->get(),
            'events' => Webhook::labels(),
            'placeholders' => WhatsAppTemplate::PLACEHOLDERS,
        ]);
    }
}

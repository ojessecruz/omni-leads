<?php

namespace App\Listeners;

use App\Domains\Webhooks\WebhookPayload;
use App\Enums\LeadStatus;
use App\Events\LeadEnriched;
use App\Events\LeadQualified;
use App\Events\LeadStatusChanged;
use App\Events\SearchJobFinished;
use App\Jobs\DeliverWebhookJob;
use App\Models\Project;
use App\Models\Webhook;

/**
 * Turns domain events into webhook deliveries for the project hooks listening to them.
 * Registered by auto-discovery (handle* methods with a typed event).
 */
class DispatchWebhooks
{
    public function handleLeadQualified(LeadQualified $event): void
    {
        $this->deliver($event->lead->project_id, 'lead.qualified', WebhookPayload::lead($event->lead));
    }

    public function handleLeadStatusChanged(LeadStatusChanged $event): void
    {
        if ($event->lead->status === LeadStatus::Contacted) {
            $this->deliver($event->lead->project_id, 'lead.contacted', WebhookPayload::lead($event->lead) + ['previous_status' => $event->from->value]);
        }
    }

    public function handleLeadEnriched(LeadEnriched $event): void
    {
        $this->deliver($event->lead->project_id, 'lead.enriched', WebhookPayload::lead($event->lead) + ['steps' => $event->steps]);
    }

    public function handleSearchJobFinished(SearchJobFinished $event): void
    {
        $projectId = $event->searchJob->icp->project_id;

        $this->deliver($projectId, 'search.finished', WebhookPayload::searchJob($event->searchJob));
    }

    /** @param  array<string, mixed>  $data */
    public static function deliver(int $projectId, string $event, array $data): int
    {
        $hooks = Webhook::query()
            ->where('project_id', $projectId)
            ->where('active', true)
            ->get()
            ->filter(fn (Webhook $w) => $w->listensTo($event));

        foreach ($hooks as $hook) {
            DeliverWebhookJob::dispatch($hook->id, $event, $data);
        }

        return $hooks->count();
    }

    public function test(Project $project, Webhook $webhook): void
    {
        DeliverWebhookJob::dispatch($webhook->id, 'webhook.test', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'message' => __('Hello from Omni Leads. If you received this, the webhook works.'),
        ]);
    }
}

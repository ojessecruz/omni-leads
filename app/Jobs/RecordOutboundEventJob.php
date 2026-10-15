<?php

namespace App\Jobs;

use App\Domains\Outbound\Data\OutboundEvent;
use App\Enums\LeadStatus;
use App\Enums\OutboundEventType;
use App\Events\LeadStatusChanged;
use App\Models\Lead;
use App\Models\OutboundConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a connector event to the lead with that e-mail in the project: logs it on
 * the lead_sources row and moves the status (sent → contacted, replied → replied).
 * Bounces and unsubscribes are only logged. Status never moves back from replied.
 */
class RecordOutboundEventJob implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $event  OutboundEvent::toArray() */
    public function __construct(public int $connectionId, public array $event)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $connection = OutboundConnection::find($this->connectionId);
        $event = OutboundEvent::fromArray($this->event);

        if ($connection === null || $event === null) {
            return;
        }

        $lead = Lead::query()
            ->where('project_id', $connection->project_id)
            ->whereRaw('lower(email) = ?', [$event->email])
            ->oldest('id')
            ->first();

        if ($lead === null) {
            return;
        }

        $row = $lead->sources()->firstOrCreate(
            ['driver' => $connection->sourceDriver(), 'external_id' => $event->campaignId ?? (string) $connection->campaign_id],
            ['raw_payload' => []],
        );

        $events = $row->raw_payload['events'] ?? [];
        $events[] = ['type' => $event->type->value, 'at' => $event->occurredAt ?? now()->toIso8601String()];
        $row->update(['raw_payload' => [...$row->raw_payload, 'events' => $events, 'last_event' => $event->type->value]]);

        $to = match ($event->type) {
            OutboundEventType::Sent => LeadStatus::Contacted,
            OutboundEventType::Replied => LeadStatus::Replied,
            default => null,
        };

        $from = $lead->status;

        if ($to === null || $from === $to || $from === LeadStatus::Replied) {
            return;
        }

        $lead->update(['status' => $to]);
        LeadStatusChanged::dispatch($lead, $from);
    }
}

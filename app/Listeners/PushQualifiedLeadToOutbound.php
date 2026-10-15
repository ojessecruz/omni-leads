<?php

namespace App\Listeners;

use App\Events\LeadQualified;
use App\Jobs\PushLeadsToOutboundJob;
use App\Models\OutboundConnection;

/** With auto-send on, a lead that becomes qualified goes straight to the project's campaign. */
class PushQualifiedLeadToOutbound
{
    public function handle(LeadQualified $event): void
    {
        if (blank($event->lead->email)) {
            return;
        }

        $connection = OutboundConnection::query()->where('project_id', $event->lead->project_id)->first();

        if ($connection?->auto_push && $connection->isReady()) {
            PushLeadsToOutboundJob::dispatch($connection->id, [$event->lead->id]);
        }
    }
}

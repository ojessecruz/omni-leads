<?php

namespace App\Listeners;

use App\Events\WhatsAppMessageStored;
use App\Jobs\RefreshWhatsAppAvatarJob;

/** A new or long-quiet conversation gets its contact's photo looked up. */
class RefreshWhatsAppAvatar
{
    public function handle(WhatsAppMessageStored $event): void
    {
        $conversation = $event->message->conversation;

        if ($conversation->avatarIsStale()) {
            RefreshWhatsAppAvatarJob::dispatch($conversation->id);
        }
    }
}

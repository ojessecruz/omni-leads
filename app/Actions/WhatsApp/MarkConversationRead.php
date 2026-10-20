<?php

namespace App\Actions\WhatsApp;

use App\Domains\WhatsApp\WhatsAppInbox;
use App\Jobs\MarkWhatsAppReadJob;
use App\Models\WhatsAppConversation;

/** Clears the unread count and sends the read receipts (blue ticks) to the contact. */
class MarkConversationRead
{
    public function __construct(private WhatsAppInbox $inbox) {}

    public function __invoke(WhatsAppConversation $conversation): void
    {
        $receipts = $this->inbox->markRead($conversation);

        if ($receipts !== []) {
            MarkWhatsAppReadJob::dispatch($conversation->id, $receipts);
        }
    }
}

<?php

namespace App\Actions\WhatsApp;

use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Jobs\MarkWhatsAppReadJob;
use App\Jobs\SendWhatsAppReplyJob;
use App\Models\WhatsAppConversation;
use RuntimeException;

/**
 * Queues a reply in a conversation (inbox or API). Writing to a number that never replied
 * counts as a first contact, so it keeps the same caps and hours. Replying marks the
 * conversation as read, as the person answering has seen it.
 */
class ReplyToConversation
{
    public function __construct(private WhatsAppInbox $inbox, private FirstContactSlots $slots) {}

    /** @throws RuntimeException when WhatsApp is not connected or the first-contact caps say no */
    public function __invoke(WhatsAppConversation $conversation, string $text): void
    {
        $user = $conversation->user;

        if (! $user->hasIntegration(IntegrationProvider::Evolution)) {
            throw new RuntimeException(__('WhatsApp is not connected. Pair your phone in Settings > Integrations.'));
        }

        if (! $this->inbox->hasReplied($conversation) && $this->slots->bookNow($user) === null) {
            throw new RuntimeException(__('This number never replied. To protect your WhatsApp, messages like this only go out on business hours and within the hourly and daily limits. Try again later.'));
        }

        SendWhatsAppReplyJob::dispatch($conversation->id, $text);

        $receipts = $this->inbox->markRead($conversation);

        if ($receipts !== []) {
            MarkWhatsAppReadJob::dispatch($conversation->id, $receipts);
        }
    }
}

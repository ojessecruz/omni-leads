<?php

namespace App\Jobs;

use App\Actions\WhatsApp\PauseDisconnectedWhatsApp;
use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\WhatsApp\WebhookParser;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Models\WhatsAppConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * A message typed in the inbox. Sending is a non-idempotent POST, so the job never retries;
 * the message joins the conversation from Evolution's answer (and again, deduplicated, from the webhook).
 * A failure because the phone dropped pauses WhatsApp sending until it is paired again.
 */
class SendWhatsAppReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $conversationId, public string $text)
    {
        $this->onQueue('default');
    }

    public function handle(EvolutionClient $evolution, WebhookParser $parser, WhatsAppInbox $inbox, PauseDisconnectedWhatsApp $pause): void
    {
        $conversation = WhatsAppConversation::with('user', 'leads')->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        try {
            $response = $evolution->withCredentials($conversation->user->credentialsFor(IntegrationProvider::Evolution))->sendText($conversation->phone, $this->text);
        } catch (RuntimeException $e) {
            $pause($conversation->user, $e);

            throw $e;
        }

        $inbox->markContacted($conversation);

        if ($sent = $parser->sent($response, $this->text)) {
            $inbox->record($conversation->user, $sent, sentVia: 'omni');
        }
    }
}

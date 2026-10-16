<?php

namespace App\Jobs;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Enums\IntegrationProvider;
use App\Models\WhatsAppConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends the read receipts (blue ticks) for messages opened in the inbox. */
class MarkWhatsAppReadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  list<array{remoteJid: string, id: string}>  $messages */
    public function __construct(public int $conversationId, public array $messages)
    {
        $this->onQueue('default');
    }

    public function handle(EvolutionClient $evolution): void
    {
        $conversation = WhatsAppConversation::with('user')->find($this->conversationId);
        $credentials = $conversation?->user->credentialsFor(IntegrationProvider::Evolution) ?? [];

        if ($conversation === null || $credentials === []) {
            return;
        }

        $evolution->withCredentials($credentials)->markAsRead($this->messages);
    }
}

<?php

namespace App\Jobs;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Models\WhatsAppConversation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Copies the contact's WhatsApp profile photo. Skips conversations checked recently. */
class RefreshWhatsAppAvatarJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(public int $conversationId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(WhatsAppInbox $inbox, EvolutionClient $evolution): void
    {
        $conversation = WhatsAppConversation::with('user')->find($this->conversationId);
        $credentials = $conversation?->user->credentialsFor(IntegrationProvider::Evolution) ?? [];

        if ($conversation === null || $credentials === [] || ! $conversation->avatarIsStale()) {
            return;
        }

        $inbox->refreshAvatar($conversation, $evolution->withCredentials($credentials));
    }
}

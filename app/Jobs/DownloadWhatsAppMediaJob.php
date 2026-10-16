<?php

namespace App\Jobs;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Models\WhatsAppMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Copies a received image/audio/video/document to the default disk. Skips media already stored. */
class DownloadWhatsAppMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** Below the Horizon supervisor timeout (60s). */
    public int $timeout = 55;

    public function __construct(public int $messageId)
    {
        $this->onQueue('default');
    }

    public function handle(WhatsAppInbox $inbox, EvolutionClient $evolution): void
    {
        $message = WhatsAppMessage::with('conversation.user')->find($this->messageId);

        if ($message === null || ! $message->needsMedia()) {
            return;
        }

        $credentials = $message->conversation->user->credentialsFor(IntegrationProvider::Evolution);

        if ($credentials === []) {
            return;
        }

        $inbox->storeMedia($message, $evolution->withCredentials($credentials));
    }
}

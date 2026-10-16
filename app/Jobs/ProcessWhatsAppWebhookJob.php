<?php

namespace App\Jobs;

use App\Domains\WhatsApp\Data\WhatsAppMessageData;
use App\Domains\WhatsApp\Data\WhatsAppStatusUpdate;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Stores the messages and acks of one Evolution webhook call. Re-runs are no-ops (unique external ids). */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  list<array<string, mixed>>  $messages  WhatsAppMessageData::toArray()
     * @param  list<array<string, mixed>>  $statuses  WhatsAppStatusUpdate::toArray()
     */
    public function __construct(public int $userId, public array $messages, public array $statuses = [])
    {
        $this->onQueue('default');
    }

    public function handle(WhatsAppInbox $inbox): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        foreach ($this->messages as $data) {
            $message = $inbox->record($user, WhatsAppMessageData::fromArray($data));

            if ($message?->needsMedia()) {
                DownloadWhatsAppMediaJob::dispatch($message->id);
            }
        }

        foreach ($this->statuses as $data) {
            if ($update = WhatsAppStatusUpdate::fromArray($data)) {
                $inbox->applyStatus($user, $update);
            }
        }
    }
}

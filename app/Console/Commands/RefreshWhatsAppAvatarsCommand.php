<?php

namespace App\Console\Commands;

use App\Jobs\RefreshWhatsAppAvatarJob;
use App\Models\WhatsAppConversation;
use Illuminate\Console\Command;

class RefreshWhatsAppAvatarsCommand extends Command
{
    protected $signature = 'whatsapp:refresh-avatars';

    protected $description = 'Queues a profile photo lookup for WhatsApp conversations never checked or checked over a week ago';

    public function handle(): int
    {
        $count = 0;

        WhatsAppConversation::query()
            ->where(fn ($q) => $q->whereNull('avatar_checked_at')->orWhere('avatar_checked_at', '<', now()->subDays(WhatsAppConversation::AVATAR_TTL_DAYS)))
            ->select('id')
            ->chunkById(500, function ($conversations) use (&$count) {
                foreach ($conversations as $conversation) {
                    RefreshWhatsAppAvatarJob::dispatch($conversation->id);
                    $count++;
                }
            });

        $this->info("{$count} photo lookup(s) queued.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\WhatsApp\RegisterWhatsAppWebhook;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Console\Command;
use RuntimeException;

class RegisterWhatsAppWebhooksCommand extends Command
{
    protected $signature = 'whatsapp:register-webhooks {--force : Send the webhook again even if the URL did not change}';

    protected $description = 'Points every paired WhatsApp instance at the app webhook (phones paired before the inbox, or after APP_URL changes)';

    public function handle(RegisterWhatsAppWebhook $register): int
    {
        $registered = 0;

        Integration::query()->where('provider', IntegrationProvider::Evolution)->each(function (Integration $integration) use ($register, &$registered) {
            try {
                $registered += (int) $register($integration, (bool) $this->option('force'));
            } catch (RuntimeException $e) {
                $this->warn("Integration #{$integration->id}: {$e->getMessage()}");
            }
        });

        $this->info("{$registered} WhatsApp webhook(s) registered.");

        return self::SUCCESS;
    }
}

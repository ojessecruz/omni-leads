<?php

namespace App\Actions\WhatsApp;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Support\LocalUrl;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Points the paired instance's webhook at the app, so replies reach the inbox. The secret and
 * the URL that was registered are kept in the integration credentials; a URL that is already
 * registered is not sent again, so this is cheap to call after every pairing and hourly.
 */
class RegisterWhatsAppWebhook
{
    public const EVENTS = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'SEND_MESSAGE'];

    public function __construct(private EvolutionClient $evolution) {}

    /**
     * @return bool true when the webhook is (now) registered
     *
     * @throws RuntimeException when Evolution refuses it
     */
    public function __invoke(Integration $integration, bool $force = false): bool
    {
        $credentials = $integration->credentials;

        if ($integration->provider !== IntegrationProvider::Evolution || ! $integration->provider->isUsable($credentials)) {
            return false;
        }

        $secret = $credentials['webhook_secret'] ?? Str::random(40);
        $url = route('whatsapp.webhook', [$integration, $secret]);

        if (LocalUrl::is($url)) {
            return false;
        }

        if (! $force && ($credentials['webhook_url'] ?? null) === $url) {
            return true;
        }

        try {
            $this->evolution->withCredentials($credentials)->setWebhook($url, self::EVENTS);
        } catch (RuntimeException $e) {
            // Kept for Settings > Integrations, which shows why replies are not arriving.
            $integration->update(['credentials' => [...$credentials, 'webhook_error' => mb_substr($e->getMessage(), 0, 300)]]);

            throw $e;
        }

        $saved = [...$credentials, 'webhook_secret' => $secret, 'webhook_url' => $url];
        unset($saved['webhook_error']);
        $integration->update(['credentials' => $saved]);

        return true;
    }
}

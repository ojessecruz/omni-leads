<?php

namespace App\Domains\Integrations;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pairs the user's phone with their Evolution server: one instance per user,
 * created on the first pairing and reused afterwards. The instance name,
 * pairing time and phone number are kept in the integration credentials.
 */
class WhatsAppPairing
{
    public function __construct(private EvolutionClient $evolution) {}

    /**
     * Creates the instance if needed and returns the QR code to scan,
     * or null when the phone turned out to be paired already.
     */
    public function start(Integration $integration): ?string
    {
        $this->assertEvolution($integration);

        if (blank($integration->credentials['instance'] ?? null)) {
            $name = 'omnileads-'.$integration->user_id.'-'.Str::lower(Str::random(6));
            $qr = $this->client($integration)->createInstance($name);
            $this->store($integration, ['instance' => $name]);

            return $qr ?? $this->client($integration)->qrCode();
        }

        return $this->refresh($integration);
    }

    /** A fresh QR code (they expire in about 40s), or null once paired. */
    public function refresh(Integration $integration): ?string
    {
        $this->assertEvolution($integration);

        $qr = $this->client($integration)->qrCode();

        if ($qr === null) {
            $this->check($integration);
        }

        return $qr;
    }

    /** Marks the integration as paired as soon as the instance reports "open". */
    public function check(Integration $integration): bool
    {
        $this->assertEvolution($integration);

        $client = $this->client($integration);

        if ($client->connectionState() !== 'open') {
            return false;
        }

        try {
            $phone = $client->pairedNumber();
        } catch (RuntimeException) {
            $phone = null;
        }

        // Paired again: the pause after a disconnect is over.
        $this->store($integration, ['connected_at' => now()->toIso8601String(), 'phone' => (string) $phone, 'disconnected_at' => '', 'disconnect_reason' => '']);

        return true;
    }

    public function unpair(Integration $integration): void
    {
        $this->assertEvolution($integration);

        $this->client($integration)->logout();
        $this->store($integration, ['connected_at' => '', 'phone' => '', 'disconnected_at' => '', 'disconnect_reason' => '']);
    }

    /** Best effort: the integration is going away, a server error must not stop it. */
    public function forget(Integration $integration): void
    {
        if (blank($integration->credentials['instance'] ?? null)) {
            return;
        }

        try {
            $this->client($integration)->deleteInstance();
        } catch (RuntimeException) {
            // The instance is left on the user's server.
        }
    }

    private function client(Integration $integration): EvolutionClient
    {
        return $this->evolution->withCredentials($integration->credentials);
    }

    /** @param  array<string, string>  $changes */
    private function store(Integration $integration, array $changes): void
    {
        $integration->update(['credentials' => array_filter([...$integration->credentials, ...$changes], fn (string $v) => $v !== '')]);
    }

    private function assertEvolution(Integration $integration): void
    {
        if ($integration->provider !== IntegrationProvider::Evolution) {
            throw new RuntimeException('Only Evolution integrations pair a phone.');
        }
    }
}

<?php

namespace App\Actions\WhatsApp;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Enums\IntegrationProvider;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Throwable;

/**
 * After a failed send: when the phone is no longer connected (logged out, restricted), every
 * send stops until it is paired again. Dropping connected_at makes the integration unusable,
 * which hides the send buttons; the scheduled sends are cancelled (their jobs then skip them,
 * so nothing goes out in a burst after pairing again); disconnected_at drives the warning.
 */
class PauseDisconnectedWhatsApp
{
    public function __construct(private EvolutionClient $evolution) {}

    /** @return bool true when sending was paused */
    public function __invoke(User $user, Throwable $error): bool
    {
        $integration = $user->integration(IntegrationProvider::Evolution);

        if ($integration === null || blank($integration->credentials['connected_at'] ?? null)) {
            return false;
        }

        try {
            $state = $this->evolution->withCredentials($user->credentialsFor(IntegrationProvider::Evolution))->connectionState();
        } catch (Throwable) {
            // The server itself did not answer: that says nothing about the phone.
            return false;
        }

        if ($state === 'open') {
            return false;
        }

        $credentials = $integration->credentials;
        unset($credentials['connected_at']);

        $integration->update(['credentials' => [
            ...$credentials,
            'disconnected_at' => now()->toIso8601String(),
            'disconnect_reason' => mb_substr($error->getMessage(), 0, 300),
        ]]);
        $user->unsetRelation('integrations');

        $this->cancelScheduled($user);

        return true;
    }

    private function cancelScheduled(User $user): void
    {
        LeadSource::query()
            ->where('driver', 'whatsapp_outbound')
            ->whereNull('raw_payload->response')
            ->whereNull('raw_payload->error')
            ->whereIn('lead_id', Lead::query()->whereHas('project', fn ($q) => $q->where('user_id', $user->id))->select('id'))
            ->get()
            ->each(fn (LeadSource $source) => $source->update(['raw_payload' => [...$source->raw_payload, 'error' => 'WhatsApp disconnected: send cancelled.', 'cancelled' => true]]));
    }
}

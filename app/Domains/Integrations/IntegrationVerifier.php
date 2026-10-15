<?php

namespace App\Domains\Integrations;

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\Integrations\Exceptions\IntegrationException;
use App\Domains\LeadSources\Contracts\VerifiesCredentials;
use App\Domains\LeadSources\LeadSourceManager;
use App\Domains\Outbound\OutboundConnectorResolver;
use App\Enums\IntegrationProvider;
use Throwable;

/**
 * Checks a key against the provider before it is saved. Every check is a
 * free call (account/health endpoints, or the "IDs only" Places SKU).
 */
class IntegrationVerifier
{
    public function __construct(
        private LeadSourceManager $sources,
        private HunterClient $hunter,
        private EvolutionClient $evolution,
        private OutboundConnectorResolver $outbound,
    ) {}

    /** @param  array<string, string>  $credentials */
    public function verify(IntegrationProvider $provider, array $credentials): void
    {
        try {
            match ($provider) {
                IntegrationProvider::GooglePlaces, IntegrationProvider::Apollo => $this->verifySource($provider, $credentials),
                IntegrationProvider::Hunter => $this->hunter->withCredentials($credentials)->verify(),
                IntegrationProvider::Evolution => $this->evolution->withCredentials($credentials)->verify(),
                IntegrationProvider::Saleshandy => $this->outbound->resolve($provider->value, $credentials)->verify(),
            };
        } catch (Throwable $e) {
            throw new IntegrationException($e->getMessage(), previous: $e);
        }
    }

    /** @param  array<string, string>  $credentials */
    private function verifySource(IntegrationProvider $provider, array $credentials): void
    {
        $driver = $this->sources->withCredentials([$provider->value => $credentials])->driver($provider->value);

        if (! $driver instanceof VerifiesCredentials) {
            throw new IntegrationException("Source [{$provider->value}] cannot verify keys.");
        }

        $driver->verify();
    }
}

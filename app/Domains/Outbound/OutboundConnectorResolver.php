<?php

namespace App\Domains\Outbound;

use App\Domains\Outbound\Connectors\Saleshandy\SaleshandyConnector;
use App\Domains\Outbound\Contracts\OutboundConnector;
use App\Domains\Outbound\Exceptions\OutboundException;

/**
 * Picks the connector strategy a project chose and builds it with the owner's
 * key (Settings > Integrations). Not a Laravel Manager: instances are never
 * cached, since every user brings their own credentials.
 */
class OutboundConnectorResolver
{
    /** Connector key => label shown in the settings select. */
    public const array CONNECTORS = [
        'saleshandy' => 'Saleshandy',
    ];

    /** @param  array<string, mixed>  $config  config('outbound') */
    public function __construct(private array $config) {}

    /** @param  array<string, mixed>  $credentials  the user's integration credentials ('key') */
    public function resolve(string $connector, array $credentials): OutboundConnector
    {
        $config = $this->config['connectors'][$connector] ?? [];
        $apiKey = (string) ($credentials['key'] ?? '');

        return match ($connector) {
            'saleshandy' => new SaleshandyConnector($config, $apiKey),
            default => throw new OutboundException("Unknown outbound connector [{$connector}]."),
        };
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return self::CONNECTORS;
    }

    public function label(string $connector): string
    {
        return self::CONNECTORS[$connector] ?? $connector;
    }
}

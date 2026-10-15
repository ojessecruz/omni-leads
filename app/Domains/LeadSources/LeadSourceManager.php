<?php

namespace App\Domains\LeadSources;

use App\Domains\LeadSources\Contracts\LeadSourceDriver;
use App\Domains\LeadSources\Drivers\Apollo\ApolloDriver;
use App\Domains\LeadSources\Drivers\CnpjBr\CnpjBrDriver;
use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesDriver;
use Illuminate\Support\Manager;

/**
 * Drivers flagged 'byok' only run with the project owner's key: call
 * withCredentials() first. Keyless drivers follow their 'enabled' flag.
 *
 * @method LeadSourceDriver driver(?string $driver = null)
 */
class LeadSourceManager extends Manager
{
    /** @var array<string, array<string, string>> driver => credentials */
    private array $credentials = [];

    /**
     * A fresh copy (no cached drivers) that builds drivers with these keys.
     *
     * @param  array<string, array<string, string>>  $credentials  User::integrationCredentials()
     */
    public function withCredentials(array $credentials): static
    {
        $copy = clone $this;
        $copy->drivers = [];
        $copy->credentials = $credentials;

        return $copy;
    }

    public function getDefaultDriver(): string
    {
        return $this->config->get('lead-sources.default', 'google_places');
    }

    /** @return list<string> */
    public function enabledDrivers(): array
    {
        $drivers = $this->config->get('lead-sources.drivers', []);

        return array_keys(array_filter(
            $drivers,
            fn (array $cfg, string $name) => $this->isImplemented($name) && (($cfg['byok'] ?? false)
                ? filled($this->credentials[$name]['key'] ?? null)
                : ($cfg['enabled'] ?? false)),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    protected function createGooglePlacesDriver(): GooglePlacesDriver
    {
        return new GooglePlacesDriver($this->driverConfig('google_places'));
    }

    protected function createApolloDriver(): ApolloDriver
    {
        return new ApolloDriver($this->driverConfig('apollo'));
    }

    protected function createCnpjBrDriver(): CnpjBrDriver
    {
        return new CnpjBrDriver($this->driverConfig('cnpj_br'));
    }

    /** @return array<string, mixed> */
    private function driverConfig(string $name): array
    {
        return [...$this->config->get("lead-sources.drivers.{$name}", []), ...($this->credentials[$name] ?? [])];
    }

    private function isImplemented(string $name): bool
    {
        return method_exists($this, 'create'.str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))).'Driver')
            || isset($this->customCreators[$name]);
    }
}

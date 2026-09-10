<?php

namespace App\Domains\LeadSources;

use App\Domains\LeadSources\Contracts\LeadSourceDriver;
use App\Domains\LeadSources\Drivers\Apollo\ApolloDriver;
use App\Domains\LeadSources\Drivers\CnpjBr\CnpjBrDriver;
use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesDriver;
use Illuminate\Support\Manager;

/**
 * @method LeadSourceDriver driver(?string $driver = null)
 */
class LeadSourceManager extends Manager
{
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
            fn (array $cfg, string $name) => ($cfg['enabled'] ?? false) && $this->isImplemented($name),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    protected function createGooglePlacesDriver(): GooglePlacesDriver
    {
        return new GooglePlacesDriver($this->config->get('lead-sources.drivers.google_places', []));
    }

    protected function createApolloDriver(): ApolloDriver
    {
        return new ApolloDriver($this->config->get('lead-sources.drivers.apollo', []));
    }

    protected function createCnpjBrDriver(): CnpjBrDriver
    {
        return new CnpjBrDriver($this->config->get('lead-sources.drivers.cnpj_br', []));
    }

    private function isImplemented(string $name): bool
    {
        return method_exists($this, 'create'.str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))).'Driver')
            || isset($this->customCreators[$name]);
    }
}

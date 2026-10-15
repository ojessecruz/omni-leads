<?php

namespace App\Enums;

/**
 * Third-party APIs the user plugs in with their own key (BYOK). The value is
 * also the lead-source driver / enrichment step / outbound connector key.
 */
enum IntegrationProvider: string
{
    case GooglePlaces = 'google_places';
    case Apollo = 'apollo';
    case Hunter = 'hunter';
    case Evolution = 'evolution';
    case Saleshandy = 'saleshandy';

    public function label(): string
    {
        return match ($this) {
            self::GooglePlaces => 'Google Places',
            self::Apollo => 'Apollo',
            self::Hunter => 'Hunter',
            self::Evolution => 'Evolution API',
            self::Saleshandy => 'Saleshandy',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GooglePlaces => __('Main lead source: searches businesses by keyword and city.'),
            self::Apollo => __('Extra source: people by job title, for B2B ICPs.'),
            self::Hunter => __('Finds the email when the website does not show one.'),
            self::Evolution => __('Checks WhatsApp on each phone and sends the WhatsApp message.'),
            self::Saleshandy => __('Cold email: sends qualified leads to a sequence.'),
        };
    }

    /** Searches do not run without it. */
    public function required(): bool
    {
        return $this === self::GooglePlaces;
    }

    public function group(): string
    {
        return match ($this) {
            self::GooglePlaces, self::Apollo => __('Lead sources'),
            self::Hunter, self::Evolution => __('Enrichment'),
            self::Saleshandy => __('Outbound'),
        };
    }

    /**
     * Credential fields, keyed by the name the client reads from its config.
     *
     * @return array<string, array{label: string, secret: bool}>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Evolution => [
                'base_url' => ['label' => __('Server URL'), 'secret' => false],
                'key' => ['label' => __('Global API key'), 'secret' => true],
            ],
            default => ['key' => ['label' => __('API key'), 'secret' => true]],
        };
    }

    /**
     * Whether saved credentials are enough to use the provider. Evolution also needs
     * the phone paired by QR code ('instance', 'connected_at'); on Omni Leads' server
     * ('mode' => 'hosted') the URL and key come from config instead.
     *
     * @param  array<string, string>  $credentials
     */
    public function isUsable(array $credentials): bool
    {
        $needed = match (true) {
            $this !== self::Evolution => ['key'],
            ($credentials['mode'] ?? 'own') === 'hosted' => ['instance', 'connected_at'],
            default => ['base_url', 'key', 'instance', 'connected_at'],
        };

        return array_all($needed, fn (string $field) => filled($credentials[$field] ?? null));
    }

    public function docsUrl(): string
    {
        return match ($this) {
            self::GooglePlaces => 'https://developers.google.com/maps/documentation/places/web-service/get-api-key',
            self::Apollo => 'https://docs.apollo.io/docs/create-api-key',
            self::Hunter => 'https://hunter.io/api-keys',
            self::Evolution => 'https://doc.evolution-api.com/v2/en/get-started/introduction',
            self::Saleshandy => 'https://open-api.saleshandy.com/api-docs',
        };
    }

    /** @return list<self> */
    public static function outbound(): array
    {
        return [self::Saleshandy];
    }
}

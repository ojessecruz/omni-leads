<?php

namespace App\Domains\Icp\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Address/place → coordinates for the map picker, through Places (New) Text Search.
 * Asks only for location fields (Pro SKU), not the contact fields the lead search pays for.
 */
class PlaceLookupClient
{
    public const string ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    private const string FIELD_MASK = 'places.displayName,places.formattedAddress,places.location';

    private const int MAX_RESULTS = 5;

    /** @param  array<string, mixed>  $config  the google_places lead-source config */
    public function __construct(private array $config) {}

    /** @return list<array{label: string, lat: float, lng: float}> */
    public function search(string $text, ?string $languageCode = null): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $response = $this->client()->post(self::ENDPOINT, array_filter([
            'textQuery' => $text,
            'pageSize' => self::MAX_RESULTS,
            'languageCode' => $languageCode,
        ], fn ($v) => $v !== null));

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Google Places responded %d: %s',
                $response->status(),
                $response->json('error.message', $response->body()),
            ));
        }

        $results = [];

        foreach ($response->json('places', []) as $place) {
            $lat = $place['location']['latitude'] ?? null;
            $lng = $place['location']['longitude'] ?? null;

            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $results[] = [
                'label' => (string) ($place['formattedAddress'] ?? $place['displayName']['text'] ?? ''),
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
        }

        return $results;
    }

    private function client(): PendingRequest
    {
        $key = $this->config['key'] ?? null;

        if (blank($key)) {
            throw new RuntimeException('GOOGLE_PLACES_KEY is not configured.');
        }

        return Http::withHeaders([
            'X-Goog-Api-Key' => $key,
            'X-Goog-FieldMask' => self::FIELD_MASK,
        ])->acceptJson()->connectTimeout(3)->timeout(10);
    }
}

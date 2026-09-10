<?php

namespace App\Domains\LeadSources\Drivers\GooglePlaces;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\LeadSourceDriver;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Domains\LeadSources\Contracts\TracksRequests;
use App\Domains\LeadSources\Data\LeadDTO;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Google Places API (New) — Text Search.
 *
 * @see https://developers.google.com/maps/documentation/places/web-service/text-search
 */
class GooglePlacesDriver implements LeadSourceDriver, TracksRequests
{
    private int $requests = 0;

    public const string ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    private const string FIELD_MASK = 'nextPageToken,places.id,places.displayName,places.formattedAddress,'
        .'places.addressComponents,places.websiteUri,places.nationalPhoneNumber,places.internationalPhoneNumber,'
        .'places.rating,places.userRatingCount,places.businessStatus,places.primaryType,places.types,places.googleMapsUri';

    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    public function name(): string
    {
        return 'google_places';
    }

    public function supports(IcpCriteria $criteria): bool
    {
        return $criteria->keywords !== [];
    }

    public function mapper(): QueryMapper
    {
        return new GooglePlacesMapper;
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    public function search(array $queries): Generator
    {
        foreach ($queries as $query) {
            yield from $this->searchQuery($query);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Generator<int, LeadDTO>
     */
    private function searchQuery(array $query): Generator
    {
        $pageToken = null;
        $maxPages = max(1, (int) ($this->config['max_pages'] ?? 3));

        for ($page = 0; $page < $maxPages; $page++) {
            $body = array_filter([
                'textQuery' => $query['text'],
                'pageSize' => 20,
                'pageToken' => $pageToken,
                'regionCode' => $query['region_code'] ?? null,
                'languageCode' => $this->languageFor($query['region_code'] ?? null),
            ], fn ($v) => $v !== null);

            $this->throttle();
            $this->requests++;

            $response = $this->client()->post(self::ENDPOINT, $body);

            if ($response->failed()) {
                throw new RuntimeException(sprintf(
                    'Google Places responded %d: %s',
                    $response->status(),
                    $response->json('error.message', $response->body()),
                ));
            }

            foreach ($response->json('places', []) as $place) {
                $dto = $this->toLead($place, $query);

                if ($dto !== null) {
                    yield $dto;
                }
            }

            $pageToken = $response->json('nextPageToken');

            if (! $pageToken) {
                break;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $query
     */
    private function toLead(array $place, array $query): ?LeadDTO
    {
        $id = $place['id'] ?? null;
        if (! $id) {
            return null;
        }

        $components = is_array($place['addressComponents'] ?? null) ? $place['addressComponents'] : [];
        $component = function (string $type) use ($components): array {
            foreach ($components as $c) {
                if (is_array($c) && in_array($type, $c['types'] ?? [], true)) {
                    return $c;
                }
            }

            return [];
        };

        $country = strtoupper((string) ($component('country')['shortText'] ?? ''));
        $city = $component('locality')['longText']
            ?? $component('administrative_area_level_2')['longText']
            ?? $component('sublocality')['longText']
            ?? null;

        $phone = $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? null;

        return new LeadDTO(
            driver: $this->name(),
            externalId: $id,
            name: $place['displayName']['text'] ?? null,
            companyName: $place['displayName']['text'] ?? null,
            domain: $this->domainFromUrl($place['websiteUri'] ?? null),
            email: null,
            phone: $phone ? preg_replace('/\D+/', '', $phone) : null,
            country: $country !== '' ? $country : strtoupper((string) ($query['region_code'] ?? 'XX')),
            city: $city,
            cnpj: null,
            rawPayload: ['query' => $query, 'place' => $place],
        );
    }

    private function domainFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./', '', $host));

        // Social network profiles do not count as an "own website".
        $social = ['facebook.com', 'instagram.com', 'linktr.ee', 'wa.me', 'whatsapp.com', 'linkedin.com', 'tiktok.com', 'youtube.com', 'x.com', 'twitter.com'];
        foreach ($social as $s) {
            if ($host === $s || str_ends_with($host, '.'.$s)) {
                return null;
            }
        }

        return $host;
    }

    private function languageFor(?string $regionCode): ?string
    {
        return match ($regionCode) {
            'br' => 'pt-BR',
            'pt' => 'pt-PT',
            'us', 'gb', 'au', 'ca' => 'en',
            'es', 'mx', 'ar', 'co', 'cl' => 'es',
            default => null,
        };
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
        ])->acceptJson()->connectTimeout(3)->timeout(15)->retry(2, 500, throw: false);
    }

    private function throttle(): void
    {
        $rps = max(1, (int) ($this->config['requests_per_second'] ?? 10));

        while (! RateLimiter::attempt('lead-source:google_places', $rps, fn () => true, 1)) {
            usleep(100_000);
        }
    }
}

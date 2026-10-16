<?php

namespace App\Domains\LeadSources\Drivers\GooglePlaces;

use App\Domains\Icp\Data\GeoArea;
use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\LeadSourceDriver;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Domains\LeadSources\Contracts\TracksRequests;
use App\Domains\LeadSources\Contracts\VerifiesCredentials;
use App\Domains\LeadSources\Data\LeadDTO;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Google Places API (New) — Text Search.
 *
 * Map areas go out as locationRestriction (the API only takes a rectangle there),
 * so places in the rectangle's corners are dropped by distance afterwards.
 *
 * Typed regions ("Leopoldina") are looked up first and, when Google knows them as a
 * place (city, state, neighborhood…), the keyword is searched inside its bounds. A plain
 * "barbearia in Leopoldina" also matches names like "Barbearia Vila Leopoldina" in another city.
 *
 * @see https://developers.google.com/maps/documentation/places/web-service/text-search
 */
class GooglePlacesDriver implements LeadSourceDriver, TracksRequests, VerifiesCredentials
{
    private int $requests = 0;

    public const string ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    /** Place types whose viewport is a usable search boundary. */
    private const array BOUNDED_TYPES = [
        'locality', 'administrative_area_level_1', 'administrative_area_level_2', 'administrative_area_level_3',
        'sublocality', 'sublocality_level_1', 'neighborhood', 'postal_code', 'colloquial_area',
    ];

    private const string FIELD_MASK = 'nextPageToken,places.id,places.displayName,places.formattedAddress,'
        .'places.addressComponents,places.location,places.websiteUri,places.nationalPhoneNumber,places.internationalPhoneNumber,'
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

    /** "IDs only" Text Search is a free SKU, so checking the key costs nothing. */
    public function verify(): void
    {
        $response = $this->client('places.id')->post(self::ENDPOINT, ['textQuery' => 'coffee', 'pageSize' => 1]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf('Google Places responded %d: %s', $response->status(), $response->json('error.message', $response->body())));
        }
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
        $area = is_array($query['area'] ?? null) ? GeoArea::fromArray($query['area']) : null;
        $maxPages = max(1, (int) ($this->config['max_pages'] ?? 3));
        $bounds = $area ? $area->boundingBox() : $this->placeBounds($query);
        // Inside the bounds the keyword alone is enough; the place name in the text would match business names again.
        $text = $bounds && ! $area && isset($query['keyword']) ? $query['keyword'] : $query['text'];

        for ($page = 0; $page < $maxPages; $page++) {
            $body = array_filter([
                'textQuery' => $text,
                'pageSize' => 20,
                'pageToken' => $pageToken,
                'regionCode' => $query['region_code'] ?? null,
                'languageCode' => $this->languageFor($query['region_code'] ?? null),
                'locationRestriction' => $bounds ? ['rectangle' => $bounds] : null,
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
                if ($area !== null && ! $this->isInside($area, $place)) {
                    continue;
                }

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

    /**
     * Viewport of the query's place, looked up once per place and cached for a month.
     *
     * @param  array<string, mixed>  $query
     * @return array{low: array{latitude: float, longitude: float}, high: array{latitude: float, longitude: float}}|null
     */
    private function placeBounds(array $query): ?array
    {
        $place = $query['place'] ?? null;

        if (! is_string($place) || $place === '') {
            return null;
        }

        $regionCode = $query['region_code'] ?? null;
        $key = 'google-places:bounds:'.md5(mb_strtolower($place).'|'.$regionCode);

        // Wrapped so "not a place" is cached too.
        return Cache::remember($key, now()->addDays(30), fn () => ['bounds' => $this->lookupBounds($place, $regionCode)])['bounds'];
    }

    /** @return array{low: array{latitude: float, longitude: float}, high: array{latitude: float, longitude: float}}|null */
    private function lookupBounds(string $place, ?string $regionCode): ?array
    {
        $this->throttle();
        $this->requests++;

        $response = $this->client('places.types,places.viewport')->post(self::ENDPOINT, array_filter([
            'textQuery' => $place,
            'pageSize' => 1,
            'regionCode' => $regionCode,
            'languageCode' => $this->languageFor($regionCode),
        ], fn ($v) => $v !== null));

        $first = $response->successful() ? $response->json('places.0') : null;
        $viewport = is_array($first) ? ($first['viewport'] ?? null) : null;

        if (! is_array($viewport) || array_intersect($first['types'] ?? [], self::BOUNDED_TYPES) === []) {
            return null;
        }

        $point = fn (mixed $p): ?array => is_array($p) && is_numeric($p['latitude'] ?? null) && is_numeric($p['longitude'] ?? null)
            ? ['latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude']]
            : null;

        $low = $point($viewport['low'] ?? null);
        $high = $point($viewport['high'] ?? null);

        return $low && $high ? ['low' => $low, 'high' => $high] : null;
    }

    /** @param  array<string, mixed>  $place */
    private function isInside(GeoArea $area, array $place): bool
    {
        $lat = $place['location']['latitude'] ?? null;
        $lng = $place['location']['longitude'] ?? null;

        return is_numeric($lat) && is_numeric($lng) && $area->contains((float) $lat, (float) $lng);
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

    private function client(string $fieldMask = self::FIELD_MASK): PendingRequest
    {
        $key = $this->config['key'] ?? null;

        if (blank($key)) {
            throw new RuntimeException('Google Places API key is not configured.');
        }

        return Http::withHeaders([
            'X-Goog-Api-Key' => $key,
            'X-Goog-FieldMask' => $fieldMask,
        ])->acceptJson()->connectTimeout(3)->timeout(15)->retry(2, 500, throw: false);
    }

    private function throttle(): void
    {
        $rps = max(1, (int) ($this->config['requests_per_second'] ?? 10));

        while (! RateLimiter::attempt('lead-source:google_places:'.md5((string) ($this->config['key'] ?? '')), $rps, fn () => true, 1)) {
            usleep(100_000);
        }
    }
}

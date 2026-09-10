<?php

namespace App\Domains\LeadSources\Drivers\Apollo;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\LeadSourceDriver;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Domains\LeadSources\Contracts\TracksRequests;
use App\Domains\LeadSources\Data\LeadDTO;
use App\Support\Countries;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Apollo.io — People Search. Returns people (decision makers) with the company nested.
 * E-mails come locked in search results; unlocking (credits) is left to enrichment.
 *
 * @see https://docs.apollo.io/reference/people-search
 */
class ApolloDriver implements LeadSourceDriver, TracksRequests
{
    public const string ENDPOINT = 'https://api.apollo.io/api/v1/mixed_people/search';

    private int $requests = 0;

    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    public function name(): string
    {
        return 'apollo';
    }

    public function supports(IcpCriteria $criteria): bool
    {
        // Without a job title or keyword the search is too broad to be worth the cost.
        return $criteria->jobTitles !== [] && ($criteria->keywords !== [] || $criteria->industry !== null);
    }

    public function mapper(): QueryMapper
    {
        return new ApolloMapper;
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
        $maxPages = max(1, (int) ($this->config['max_pages'] ?? 3));
        $perPage = min(100, max(1, (int) ($this->config['per_page'] ?? 100)));

        for ($page = 1; $page <= $maxPages; $page++) {
            $this->throttle();
            $this->requests++;

            $response = Http::withHeaders(['x-api-key' => $this->key()])
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(30)
                ->retry(2, 1000, throw: false)
                ->post(self::ENDPOINT, array_filter($query, fn ($v) => $v !== [] && $v !== null) + ['page' => $page, 'per_page' => $perPage]);

            if ($response->failed()) {
                throw new RuntimeException(sprintf('Apollo responded %d: %s', $response->status(), $response->json('error', $response->body())));
            }

            $people = $response->json('people', []);

            foreach ($people as $person) {
                $dto = $this->toLead($person, $query);

                if ($dto !== null) {
                    yield $dto;
                }
            }

            $totalPages = (int) $response->json('pagination.total_pages', 1);

            if ($people === [] || $page >= $totalPages) {
                break;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $query
     */
    private function toLead(array $person, array $query): ?LeadDTO
    {
        $id = $person['id'] ?? null;
        $org = is_array($person['organization'] ?? null) ? $person['organization'] : [];

        if (! $id) {
            return null;
        }

        $email = $person['email'] ?? null;
        if (! is_string($email) || str_contains($email, 'email_not_unlocked') || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }

        $domain = $org['primary_domain'] ?? null;
        if (! $domain && ! empty($org['website_url'])) {
            $host = parse_url((string) $org['website_url'], PHP_URL_HOST);
            $domain = is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : null;
        }

        $country = Countries::code((string) ($org['country'] ?? $person['country'] ?? '')) ?? 'XX';
        $phone = $org['phone'] ?? $org['sanitized_phone'] ?? null;

        return new LeadDTO(
            driver: $this->name(),
            externalId: (string) $id,
            name: trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? '')) ?: ($person['name'] ?? null),
            companyName: $org['name'] ?? null,
            domain: $domain ? strtolower($domain) : null,
            email: $email,
            phone: is_string($phone) ? preg_replace('/\D+/', '', $phone) : null,
            country: $country,
            city: $org['city'] ?? $person['city'] ?? null,
            cnpj: null,
            rawPayload: ['query' => $query, 'person' => $person],
        );
    }

    private function key(): string
    {
        $key = $this->config['key'] ?? null;

        if (blank($key)) {
            throw new RuntimeException('APOLLO_KEY is not configured.');
        }

        return $key;
    }

    private function throttle(): void
    {
        $rpm = max(1, (int) ($this->config['requests_per_minute'] ?? 50));

        while (! RateLimiter::attempt('lead-source:apollo', $rpm, fn () => true, 60)) {
            usleep(250_000);
        }
    }
}

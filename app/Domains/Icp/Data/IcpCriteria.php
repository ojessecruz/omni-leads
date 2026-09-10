<?php

namespace App\Domains\Icp\Data;

use App\Enums\CompanySize;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * ICP criteria. Unknown fields are ignored (forward-compatible).
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class IcpCriteria implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $countries  ISO-3166 alpha-2, uppercase
     * @param  list<string>  $regions
     * @param  list<string>  $jobTitles
     */
    public function __construct(
        public ?string $industry = null,
        public array $keywords = [],
        public array $countries = [],
        public array $regions = [],
        public ?CompanySize $companySize = null,
        public array $jobTitles = [],
        public ?bool $hasWebsite = null,
        public ?int $minReviews = null,
        public ?float $minRating = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $signals = is_array($data['signals'] ?? null) ? $data['signals'] : [];

        return new self(
            industry: self::nullableString($data['industry'] ?? null),
            keywords: self::stringList($data['keywords'] ?? []),
            countries: array_values(array_unique(array_map(
                fn (string $c) => strtoupper($c),
                array_filter(self::stringList($data['countries'] ?? []), fn ($c) => preg_match('/^[a-z]{2}$/i', $c) === 1),
            ))),
            regions: self::stringList($data['regions'] ?? []),
            companySize: CompanySize::tryFrom((string) ($data['company_size'] ?? '')),
            jobTitles: self::stringList($data['job_titles'] ?? []),
            hasWebsite: isset($signals['has_website']) ? filter_var($signals['has_website'], FILTER_VALIDATE_BOOLEAN) : null,
            minReviews: is_numeric($signals['min_reviews'] ?? null) ? (int) $signals['min_reviews'] : null,
            minRating: is_numeric($signals['min_rating'] ?? null) ? (float) $signals['min_rating'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'industry' => $this->industry,
            'keywords' => $this->keywords,
            'countries' => $this->countries,
            'regions' => $this->regions,
            'company_size' => $this->companySize?->value,
            'job_titles' => $this->jobTitles,
            'signals' => [
                'has_website' => $this->hasWebsite,
                'min_reviews' => $this->minReviews,
                'min_rating' => $this->minRating,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function includesCountry(string $country): bool
    {
        return in_array(strtoupper($country), $this->countries, true);
    }

    /** Signals that can only be evaluated after discovery (filterable). */
    public function hasSignals(): bool
    {
        return $this->hasWebsite !== null || $this->minReviews !== null || $this->minRating !== null;
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\n]/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $value);

        return array_values(array_unique(array_filter($items, fn ($v) => $v !== '')));
    }
}

<?php

namespace App\Domains\LeadSources\Drivers\GooglePlaces;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Support\Countries;

/**
 * Grid of text queries: "{keyword} in {region}" plus "{keyword}" restricted to each map area.
 * Without regions or areas, uses the country.
 * Each grid cell is an independent search (capped at 60 results each),
 * so splitting by city/neighborhood multiplies the reach.
 */
class GooglePlacesMapper implements QueryMapper
{
    public function map(IcpCriteria $criteria): array
    {
        $queries = [];
        $country = count($criteria->countries) === 1 ? $criteria->countries[0] : null;

        foreach ($criteria->keywords as $keyword) {
            if ($criteria->hasLocalities()) {
                foreach ($criteria->regions as $region) {
                    $queries[] = [
                        'text' => "{$keyword} in ".$this->withCountry($region, $country),
                        'keyword' => $keyword,
                        'region' => $region,
                        'region_code' => $country ? strtolower($country) : null,
                    ];
                }

                foreach ($criteria->areas as $area) {
                    $queries[] = [
                        'text' => $keyword,
                        'keyword' => $keyword,
                        'region' => $area->displayName(),
                        'region_code' => $country ? strtolower($country) : null,
                        'area' => $area->toArray(),
                    ];
                }

                continue;
            }

            foreach ($criteria->countries ?: [null] as $country) {
                $queries[] = [
                    'text' => $country ? "{$keyword} in ".Countries::name($country) : $keyword,
                    'keyword' => $keyword,
                    'region' => null,
                    'region_code' => $country ? strtolower($country) : null,
                ];
            }
        }

        return $queries;
    }

    /**
     * Pins an ambiguous region to the ICP's only country ("SC" alone resolves to South Carolina).
     */
    private function withCountry(string $region, ?string $country): string
    {
        if ($country === null) {
            return $region;
        }

        $name = Countries::name($country);

        return str_contains(mb_strtolower($region), mb_strtolower($name)) ? $region : "{$region}, {$name}";
    }

    public function unsupported(IcpCriteria $criteria): array
    {
        $unsupported = [];

        if ($criteria->industry !== null) {
            $unsupported[] = 'industry';
        }
        if ($criteria->companySize !== null) {
            $unsupported[] = 'company_size';
        }
        if ($criteria->jobTitles !== []) {
            $unsupported[] = 'job_titles';
        }
        if ($criteria->hasSignals()) {
            $unsupported[] = 'signals';
        }
        // With regions or areas, the country becomes a post-filter (Places resolves the place on its own).
        if ($criteria->hasLocalities() && $criteria->countries !== []) {
            $unsupported[] = 'countries';
        }

        return $unsupported;
    }
}

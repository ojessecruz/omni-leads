<?php

namespace App\Domains\LeadSources\Drivers\GooglePlaces;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Support\Countries;

/**
 * Grid of text queries: "{keyword} in {region}". Without regions, uses the country.
 * Each grid cell is an independent search (capped at 60 results each),
 * so splitting by city/neighborhood multiplies the reach.
 */
class GooglePlacesMapper implements QueryMapper
{
    public function map(IcpCriteria $criteria): array
    {
        $queries = [];

        foreach ($criteria->keywords as $keyword) {
            if ($criteria->regions !== []) {
                foreach ($criteria->regions as $region) {
                    $queries[] = [
                        'text' => "{$keyword} in {$region}",
                        'keyword' => $keyword,
                        'region' => $region,
                        'region_code' => null,
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
        // With regions, the country becomes a post-filter (Places resolves the region on its own).
        if ($criteria->regions !== [] && $criteria->countries !== []) {
            $unsupported[] = 'countries';
        }

        return $unsupported;
    }
}

<?php

namespace App\Domains\LeadSources\Drivers\Apollo;

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Contracts\QueryMapper;
use App\Enums\CompanySize;
use App\Support\Countries;

/**
 * Apollo filters people by job title, company location, size and industry keywords.
 * A single query with every filter combined (the API already intersects them).
 */
class ApolloMapper implements QueryMapper
{
    public function map(IcpCriteria $criteria): array
    {
        $locations = $criteria->regions !== []
            ? $this->regionsWithCountries($criteria)
            : array_map(fn (string $c) => Countries::englishName($c), $criteria->countries);

        $keywords = array_values(array_unique(array_filter([
            $criteria->industry,
            ...$criteria->keywords,
        ])));

        return [[
            'person_titles' => $criteria->jobTitles,
            'organization_locations' => $locations,
            'organization_num_employees_ranges' => $this->employeeRanges($criteria->companySize),
            'q_organization_keyword_tags' => $keywords,
        ]];
    }

    public function unsupported(IcpCriteria $criteria): array
    {
        return $criteria->hasSignals() ? ['signals'] : [];
    }

    /**
     * "São Paulo, Brazil" — Apollo resolves better with the country appended.
     *
     * @return list<string>
     */
    private function regionsWithCountries(IcpCriteria $criteria): array
    {
        $countries = $criteria->countries !== [] ? $criteria->countries : [null];
        $out = [];

        foreach ($criteria->regions as $region) {
            foreach ($countries as $country) {
                $out[] = $country ? "{$region}, ".Countries::englishName($country) : $region;
            }
        }

        return $out;
    }

    /** @return list<string> ranges in the API's "min,max" format */
    private function employeeRanges(?CompanySize $size): array
    {
        return match ($size) {
            CompanySize::Micro => ['1,10'],
            CompanySize::Small => ['11,20', '21,50'],
            CompanySize::Medium => ['51,100', '101,200'],
            CompanySize::Large => ['201,500', '501,1000', '1001,2000', '2001,5000', '5001,10000', '10001,'],
            null => [],
        };
    }
}

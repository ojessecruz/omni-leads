<?php

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesMapper;

test('gera grid keyword x região sem region_code', function () {
    $criteria = IcpCriteria::fromArray([
        'keywords' => ['clínica de estética', 'day spa'],
        'regions' => ['São Paulo', 'Miami'],
        'countries' => ['BR', 'US'],
    ]);

    $queries = (new GooglePlacesMapper)->map($criteria);

    expect($queries)->toHaveCount(4)
        ->and(array_column($queries, 'text'))->toBe([
            'clínica de estética in São Paulo',
            'clínica de estética in Miami',
            'day spa in São Paulo',
            'day spa in Miami',
        ])
        ->and($queries[0]['region_code'])->toBeNull();
});

test('sem regiões usa o país como localidade e region_code', function () {
    $criteria = IcpCriteria::fromArray(['keywords' => ['day spa'], 'countries' => ['BR', 'PT']]);

    $queries = (new GooglePlacesMapper)->map($criteria);

    expect(array_column($queries, 'text'))->toBe(['day spa in Brazil', 'day spa in Portugal'])
        ->and(array_column($queries, 'region_code'))->toBe(['br', 'pt']);
});

test('lista critérios não suportados para filtro pós', function () {
    $criteria = IcpCriteria::fromArray([
        'industry' => 'estética',
        'keywords' => ['spa'],
        'regions' => ['São Paulo'],
        'countries' => ['BR'],
        'company_size' => '1-10',
        'job_titles' => ['owner'],
        'signals' => ['min_rating' => 4],
    ]);

    expect((new GooglePlacesMapper)->unsupported($criteria))
        ->toBe(['industry', 'company_size', 'job_titles', 'signals', 'countries']);
});

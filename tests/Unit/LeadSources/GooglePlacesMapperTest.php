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

test('áreas do mapa viram buscas pela keyword restritas ao círculo, junto com as regiões', function () {
    $criteria = IcpCriteria::fromArray([
        'keywords' => ['day spa'],
        'regions' => ['Miami'],
        'countries' => ['BR'],
        'areas' => [['lat' => -23.6, 'lng' => -46.66, 'radius_km' => 3, 'label' => 'Moema']],
    ]);

    $mapper = new GooglePlacesMapper;
    $queries = $mapper->map($criteria);

    expect(array_column($queries, 'text'))->toBe(['day spa in Miami, Brazil', 'day spa'])
        ->and($queries[1]['area'])->toBe(['lat' => -23.6, 'lng' => -46.66, 'radius_km' => 3.0, 'label' => 'Moema'])
        ->and($queries[1]['region_code'])->toBe('br')
        ->and($mapper->unsupported(IcpCriteria::fromArray(['keywords' => ['spa'], 'countries' => ['BR'], 'areas' => $criteria->toArray()['areas']])))
        ->toBe(['countries']);
});

test('com um só país, a região vai com o nome do país e o region_code', function () {
    $criteria = IcpCriteria::fromArray([
        'keywords' => ['barbearia'],
        'regions' => ['SC', 'Juiz de Fora, MG', 'Lisboa, Portugal'],
        'countries' => ['BR'],
    ]);

    $queries = (new GooglePlacesMapper)->map($criteria);

    expect(array_column($queries, 'text'))->toBe([
        'barbearia in SC, Brazil',
        'barbearia in Juiz de Fora, MG, Brazil',
        'barbearia in Lisboa, Portugal, Brazil',
    ])->and(array_unique(array_column($queries, 'region_code')))->toBe(['br']);
});

test('não repete o país quando a região já o menciona', function () {
    $criteria = IcpCriteria::fromArray(['keywords' => ['day spa'], 'regions' => ['Lisboa, Portugal'], 'countries' => ['PT']]);

    expect((new GooglePlacesMapper)->map($criteria)[0]['text'])->toBe('day spa in Lisboa, Portugal');
});

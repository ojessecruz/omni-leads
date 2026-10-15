<?php

use App\Domains\Icp\Data\GeoArea;
use App\Domains\Icp\Data\IcpCriteria;
use App\Enums\CompanySize;

test('normaliza países para uppercase e descarta inválidos', function () {
    $criteria = IcpCriteria::fromArray(['countries' => ['br', 'US', 'brasil', 'pt', 'br']]);

    expect($criteria->countries)->toBe(['BR', 'US', 'PT']);
});

test('aceita listas como string separada por vírgula', function () {
    $criteria = IcpCriteria::fromArray(['keywords' => 'spa, day spa ,, clínica']);

    expect($criteria->keywords)->toBe(['spa', 'day spa', 'clínica']);
});

test('mapeia company_size para enum e ignora valores fora do enum', function () {
    expect(IcpCriteria::fromArray(['company_size' => '11-50'])->companySize)->toBe(CompanySize::Small)
        ->and(IcpCriteria::fromArray(['company_size' => 'gigante'])->companySize)->toBeNull();
});

test('ignora campos desconhecidos e faz round-trip via toArray', function () {
    $input = [
        'industry' => 'estética',
        'keywords' => ['spa'],
        'countries' => ['BR'],
        'regions' => ['São Paulo'],
        'company_size' => '1-10',
        'job_titles' => ['owner'],
        'signals' => ['has_website' => true, 'min_reviews' => '10', 'min_rating' => 4.5],
        'campo_novo' => 'ignorado',
    ];

    $criteria = IcpCriteria::fromArray($input);

    expect($criteria->hasWebsite)->toBeTrue()
        ->and($criteria->minReviews)->toBe(10)
        ->and($criteria->minRating)->toBe(4.5)
        ->and($criteria->hasSignals())->toBeTrue()
        ->and(IcpCriteria::fromArray($criteria->toArray())->toArray())->toBe($criteria->toArray())
        ->and($criteria->toArray())->not->toHaveKey('campo_novo');
});

test('áreas do mapa: limita o raio, descarta coordenadas inválidas e faz round-trip', function () {
    $criteria = IcpCriteria::fromArray(['areas' => [
        ['lat' => -23.6, 'lng' => -46.66, 'radius_km' => 3, 'label' => 'Moema, São Paulo'],
        ['lat' => -23.5, 'lng' => -46.6, 'radius_km' => 500],
        ['lat' => 120, 'lng' => 0, 'radius_km' => 5],
        ['lat' => 'x', 'lng' => 0, 'radius_km' => 5],
        'lixo',
    ]]);

    expect($criteria->areas)->toHaveCount(2)
        ->and($criteria->areas[0]->displayName())->toBe('Moema, São Paulo (3 km)')
        ->and($criteria->areas[1]->radiusKm)->toBe(GeoArea::MAX_RADIUS_KM)
        ->and($criteria->hasLocalities())->toBeTrue()
        ->and(IcpCriteria::fromArray($criteria->toArray())->toArray())->toBe($criteria->toArray());
});

test('área contém pontos pela distância real e o retângulo envolve o círculo', function () {
    $area = new GeoArea(lat: -23.55, lng: -46.63, radiusKm: 5);
    $box = $area->boundingBox();

    // ~4.4 km north is inside; the rectangle corner (~6.4 km) is not.
    expect($area->contains(-23.51, -46.63))->toBeTrue()
        ->and($area->contains($box['high']['latitude'], $box['high']['longitude']))->toBeFalse()
        ->and($box['low']['latitude'])->toBeLessThan(-23.55)
        ->and($box['high']['longitude'])->toBeGreaterThan(-46.63);
});

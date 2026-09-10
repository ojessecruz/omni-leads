<?php

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

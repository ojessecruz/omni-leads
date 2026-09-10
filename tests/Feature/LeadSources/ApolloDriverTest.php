<?php

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\LeadSources\Drivers\Apollo\ApolloDriver;
use App\Domains\LeadSources\Drivers\Apollo\ApolloMapper;
use App\Domains\LeadSources\LeadSourceManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('lead-sources.drivers.apollo', ['enabled' => true, 'key' => 'ak', 'max_pages' => 3, 'per_page' => 100, 'requests_per_minute' => 1000]);
});

test('mapper combina cargos, locais com país em inglês, faixa de funcionários e keywords', function () {
    $criteria = IcpCriteria::fromArray([
        'industry' => 'estética', 'keywords' => ['day spa'], 'countries' => ['BR', 'PT'], 'regions' => ['São Paulo'],
        'company_size' => '11-50', 'job_titles' => ['owner', 'founder'], 'signals' => ['min_rating' => 4],
    ]);

    $mapper = new ApolloMapper;
    $q = $mapper->map($criteria)[0];

    expect($q['person_titles'])->toBe(['owner', 'founder'])
        ->and($q['organization_locations'])->toBe(['São Paulo, Brazil', 'São Paulo, Portugal'])
        ->and($q['organization_num_employees_ranges'])->toBe(['11,20', '21,50'])
        ->and($q['q_organization_keyword_tags'])->toBe(['estética', 'day spa'])
        ->and($mapper->unsupported($criteria))->toBe(['signals']);

    $noRegions = IcpCriteria::fromArray(['keywords' => ['spa'], 'countries' => ['US'], 'job_titles' => ['ceo']]);
    expect($mapper->map($noRegions)[0]['organization_locations'])->toBe(['United States']);
});

test('driver exige cargo e keyword para valer a busca', function () {
    $driver = app(LeadSourceManager::class)->driver('apollo');

    expect($driver->supports(IcpCriteria::fromArray(['keywords' => ['spa']])))->toBeFalse()
        ->and($driver->supports(IcpCriteria::fromArray(['keywords' => ['spa'], 'job_titles' => ['owner']])))->toBeTrue();
});

test('pagina, normaliza pessoa + empresa e descarta e-mail bloqueado', function () {
    $person = fn (string $id, array $extra = []) => array_merge([
        'id' => $id, 'first_name' => 'Ana', 'last_name' => 'Silva', 'title' => 'Owner',
        'email' => 'email_not_unlocked@domain.com',
        'organization' => ['name' => 'Spa Ana', 'primary_domain' => 'spaana.com.br', 'phone' => '+55 11 3333-0000', 'city' => 'São Paulo', 'country' => 'Brazil'],
    ], $extra);

    Http::fake([
        ApolloDriver::ENDPOINT => Http::sequence()
            ->push(['people' => [$person('p1'), $person('p2', ['email' => 'ana@spaana.com.br'])], 'pagination' => ['page' => 1, 'total_pages' => 2]])
            ->push(['people' => [$person('p3', ['organization' => ['name' => 'X', 'website_url' => 'https://www.x.com/', 'country' => 'Portugal']])], 'pagination' => ['page' => 2, 'total_pages' => 2]]),
    ]);

    $driver = app(LeadSourceManager::class)->driver('apollo');
    $leads = iterator_to_array($driver->search([['person_titles' => ['owner'], 'q_organization_keyword_tags' => ['spa']]]), false);

    expect($leads)->toHaveCount(3)
        ->and($leads[0]->name)->toBe('Ana Silva')
        ->and($leads[0]->companyName)->toBe('Spa Ana')
        ->and($leads[0]->domain)->toBe('spaana.com.br')
        ->and($leads[0]->email)->toBeNull()
        ->and($leads[0]->phone)->toBe('551133330000')
        ->and($leads[0]->country)->toBe('BR')
        ->and($leads[1]->email)->toBe('ana@spaana.com.br')
        ->and($leads[2]->domain)->toBe('x.com')
        ->and($leads[2]->country)->toBe('PT')
        ->and($driver->requestCount())->toBe(2);

    Http::assertSent(fn ($req) => $req->hasHeader('x-api-key', 'ak') && $req['page'] === 1 && $req['per_page'] === 100);
});

test('erro da API vira exceção com mensagem', function () {
    Http::fake([ApolloDriver::ENDPOINT => Http::response(['error' => 'Invalid API key'], 401)]);

    $driver = app(LeadSourceManager::class)->driver('apollo');

    expect(fn () => iterator_to_array($driver->search([['person_titles' => ['ceo']]]), false))
        ->toThrow(RuntimeException::class, 'Invalid API key');
});

test('manager habilita apollo quando configurado', function () {
    config()->set('lead-sources.drivers.google_places.enabled', false);

    expect(app(LeadSourceManager::class)->enabledDrivers())->toBe(['apollo']);
});

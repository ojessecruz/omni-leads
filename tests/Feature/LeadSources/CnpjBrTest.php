<?php

use App\Domains\Icp\Data\IcpCriteria;
use App\Domains\Leads\LeadUpserter;
use App\Domains\LeadSources\Drivers\CnpjBr\CnpjBrMapper;
use App\Domains\LeadSources\LeadSourceManager;
use App\Jobs\DispatchIcpSearchJob;
use App\Jobs\EnrichLeadJob;
use App\Jobs\SourceSearchJob;
use App\Models\CnaeMapping;
use App\Models\CnpjEstablishment;
use App\Models\CnpjMunicipality;
use App\Models\Icp;
use Database\Seeders\CnaeMappingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function importFixtures(): void
{
    $dir = base_path('tests/fixtures/cnpj');
    test()->artisan('cnpj:import', ['type' => 'cnaes', 'path' => "{$dir}/Cnaes.csv"])->assertSuccessful();
    test()->artisan('cnpj:import', ['type' => 'municipios', 'path' => "{$dir}/Municipios.csv"])->assertSuccessful();
    test()->artisan('cnpj:import', ['type' => 'estabelecimentos', 'path' => "{$dir}/Estabelecimentos.csv"])->assertSuccessful();
    test()->artisan('cnpj:import', ['type' => 'empresas', 'path' => "{$dir}/Empresas.csv"])->assertSuccessful();
}

beforeEach(function () {
    config()->set('lead-sources.drivers.cnpj_br', ['enabled' => true, 'max_results_per_query' => 500, 'cost_per_request' => 0]);
    config()->set('lead-sources.drivers.google_places.enabled', false);
});

test('importa cnaes, municípios, estabelecimentos ativos e razão social das empresas', function () {
    importFixtures();

    expect(CnpjMunicipality::find('7107')->name)->toBe('Sao Paulo')
        ->and(CnpjEstablishment::count())->toBe(5) // 6 no arquivo, 1 inativo
        ->and(CnpjEstablishment::where('situacao', '!=', '02')->count())->toBe(0);

    $bella = CnpjEstablishment::find('12345678000195');

    expect($bella->nome_fantasia)->toBe('ESPAÇO BELLA ESTÉTICA')
        ->and($bella->razao_social)->toBe('BELLA ESTETICA LTDA')
        ->and($bella->porte)->toBe('01')
        ->and($bella->telefone1)->toBe('1133221000')
        ->and($bella->email)->toBe('contato@bella.com.br')
        ->and($bella->is_headquarters)->toBeTrue()
        ->and($bella->data_inicio->format('Y-m-d'))->toBe('2015-03-01')
        ->and(CnpjEstablishment::find('12345678000276')->is_headquarters)->toBeFalse();

    // re-importing does not duplicate
    importFixtures();
    expect(CnpjEstablishment::count())->toBe(5);
});

test('--all inclui inativos e tipo inválido falha', function () {
    $dir = base_path('tests/fixtures/cnpj');
    $this->artisan('cnpj:import', ['type' => 'estabelecimentos', 'path' => "{$dir}/Estabelecimentos.csv", '--all' => true])->assertSuccessful();
    expect(CnpjEstablishment::count())->toBe(6);

    $this->artisan('cnpj:import', ['type' => 'xpto', 'path' => "{$dir}/Cnaes.csv"])->assertFailed();
});

test('mapper resolve keyword por de-para, por descrição e regiões por município/UF', function () {
    importFixtures();
    $this->seed(CnaeMappingSeeder::class);

    $mapper = new CnpjBrMapper;

    expect($mapper->resolveCnaes('clínica de estética'))->toBe(['9602502', '9602501', '8630503'])
        ->and($mapper->resolveCnaes('restaurante'))->toBe(['5611201'])
        ->and($mapper->resolveCnaes('Cabeleireiros'))->toBe(['9602501']) // no mapping → description
        ->and($mapper->resolveCnaes('manicure'))->toBe(['9602501'])
        ->and($mapper->resolveCnaes('foguetes espaciais'))->toBe([]);

    $criteria = IcpCriteria::fromArray([
        'industry' => 'estética', 'keywords' => ['clínica de estética', 'foguetes espaciais'],
        'countries' => ['BR'], 'regions' => ['São Paulo', 'rj', 'Atlântida'],
        'job_titles' => ['owner'], 'company_size' => '1-10', 'signals' => ['min_rating' => 4],
    ]);

    $queries = $mapper->map($criteria);

    expect($queries)->toHaveCount(2) // rockets stay out
        ->and($queries[0]['keyword'])->toBe('clínica de estética')
        ->and($queries[0]['municipio_codes'])->toBe(['7107'])
        ->and($queries[0]['ufs'])->toBe(['RJ'])
        ->and($queries[0]['unresolved_regions'])->toBe(['Atlântida'])
        ->and($queries[1]['keyword'])->toBe('estética')
        ->and($mapper->unsupported($criteria))->toBe(['job_titles', 'company_size', 'signals']);
});

test('driver só suporta ICP com BR e base importada, e busca por CNAE + município', function () {
    $driver = app(LeadSourceManager::class)->driver('cnpj_br');
    $criteria = IcpCriteria::fromArray(['keywords' => ['estética'], 'countries' => ['BR']]);

    expect($driver->supports($criteria))->toBeFalse(); // base vazia

    importFixtures();

    expect($driver->supports($criteria))->toBeTrue()
        ->and($driver->supports(IcpCriteria::fromArray(['keywords' => ['estética'], 'countries' => ['US']])))->toBeFalse();

    DB::enableQueryLog();
    $leads = iterator_to_array($driver->search([[
        'keyword' => 'estética', 'cnae_codes' => ['9602502'], 'municipio_codes' => ['7107'], 'ufs' => [],
    ]]), false);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(2) // establishments + municipalities in bulk, no N+1
        ->and($leads)->toHaveCount(2) // Bella HQ + branch; inactive and RJ stay out
        ->and($leads[0]->cnpj)->toBe('12345678000195')
        ->and($leads[0]->name)->toBe('Espaço Bella Estética')
        ->and($leads[0]->companyName)->toBe('Bella Estetica Ltda')
        ->and($leads[0]->phone)->toBe('551133221000')
        ->and($leads[0]->email)->toBe('contato@bella.com.br')
        ->and($leads[0]->city)->toBe('Sao Paulo')
        ->and($leads[0]->country)->toBe('BR')
        ->and($leads[0]->rawPayload['establishment']['cnae_principal'])->toBe('9602502');

    // by UF when there is no municipality
    $byUf = iterator_to_array($driver->search([['keyword' => 'x', 'cnae_codes' => ['9602502'], 'municipio_codes' => [], 'ufs' => ['RJ']]]), false);
    expect($byUf)->toHaveCount(1)->and($byUf[0]->cnpj)->toBe('55555555000144');
});

test('pipeline completo: dispatch cria search_job cnpj_br e leads deduplicados por CNPJ', function () {
    Queue::fake([SourceSearchJob::class, EnrichLeadJob::class]);
    importFixtures();
    CnaeMapping::create(['keyword' => 'estética', 'cnae_code' => '9602502']);

    $icp = Icp::factory()->create(['criteria' => ['industry' => 'estética', 'keywords' => ['estética'], 'countries' => ['BR'], 'regions' => ['São Paulo']]]);

    (new DispatchIcpSearchJob($icp->id))->handle(app(LeadSourceManager::class));

    $job = $icp->searchJobs()->where('driver', 'cnpj_br')->sole();
    expect($job->params['queries'])->toHaveCount(1); // same keyword and industry → one query

    (new SourceSearchJob($job->id))->handle(app(LeadSourceManager::class), app(LeadUpserter::class));

    expect($job->fresh()->results_count)->toBe(2)
        ->and($icp->leads()->count())->toBe(2)
        ->and($icp->leads()->whereNotNull('cnpj')->count())->toBe(2);
});

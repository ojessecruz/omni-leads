<?php

use App\Domains\Leads\LeadUpserter;
use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesDriver;
use App\Domains\LeadSources\LeadSourceManager;
use App\Enums\IcpStatus;
use App\Enums\IntegrationProvider;
use App\Enums\SearchJobStatus;
use App\Jobs\DispatchIcpSearchJob;
use App\Jobs\EnrichLeadJob;
use App\Jobs\SourceSearchJob;
use App\Models\Icp;
use App\Models\SearchJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('lead-sources.drivers.google_places', ['byok' => true, 'max_pages' => 3, 'requests_per_second' => 100]);
});

function icpWithPlacesKey(array $attributes = []): Icp
{
    $icp = Icp::factory()->create($attributes);
    connectIntegration($icp->project->user, IntegrationProvider::GooglePlaces, ['key' => 'test-key']);

    return $icp;
}

function searchJobWithPlacesKey(array $attributes = []): SearchJob
{
    return SearchJob::factory()->for(icpWithPlacesKey())->create($attributes);
}

test('DispatchIcpSearchJob cria um search_job por driver compatível e ativa o ICP', function () {
    Queue::fake([SourceSearchJob::class]);
    $icp = icpWithPlacesKey();

    (new DispatchIcpSearchJob($icp->id))->handle(app(LeadSourceManager::class));

    $job = $icp->searchJobs()->sole();

    expect($job->driver)->toBe('google_places')
        ->and($job->status)->toBe(SearchJobStatus::Pending)
        ->and($job->params['queries'])->toHaveCount(2)
        ->and($job->params['unsupported'])->toContain('signals')
        ->and($icp->fresh()->status)->toBe(IcpStatus::Active);

    Queue::assertPushed(SourceSearchJob::class, fn ($j) => $j->searchJobId === $job->id && $j->queue === 'discovery');
});

test('ICP sem keywords não gera search_job', function () {
    Queue::fake();
    $icp = icpWithPlacesKey(['criteria' => ['keywords' => [], 'countries' => ['BR']]]);

    (new DispatchIcpSearchJob($icp->id))->handle(app(LeadSourceManager::class));

    expect($icp->searchJobs()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('sem a chave do Google Places na conta nenhuma busca é criada', function () {
    Queue::fake();
    $icp = Icp::factory()->create();

    (new DispatchIcpSearchJob($icp->id))->handle(app(LeadSourceManager::class));

    expect($icp->searchJobs()->count())->toBe(0);
});

test('SourceSearchJob busca, faz upsert, enfileira enriquecimento e marca done', function () {
    Queue::fake([EnrichLeadJob::class]);
    Http::fake([GooglePlacesDriver::ENDPOINT => Http::response(['places' => [
        placePayload('p1'), placePayload('p2', ['websiteUri' => 'https://www.spa-p1.com.br']), // p2 collides with p1 on the domain
    ]])]);
    $searchJob = searchJobWithPlacesKey();

    (new SourceSearchJob($searchJob->id))->handle(app(LeadSourceManager::class), app(LeadUpserter::class));

    $searchJob->refresh();

    expect($searchJob->status)->toBe(SearchJobStatus::Done)
        ->and($searchJob->results_count)->toBe(2)
        ->and($searchJob->finished_at)->not->toBeNull()
        ->and($searchJob->icp->leads()->count())->toBe(1)
        ->and($searchJob->icp->leads()->first()->sources()->count())->toBe(2);

    Queue::assertPushed(EnrichLeadJob::class, 2);
    Queue::assertPushed(EnrichLeadJob::class, fn ($j) => $j->queue === 'enrichment');
});

test('SourceSearchJob descarta leads de países fora do ICP', function () {
    Queue::fake([EnrichLeadJob::class]);
    Http::fake([GooglePlacesDriver::ENDPOINT => Http::response(['places' => [
        placePayload('br'),
        placePayload('us', [
            'websiteUri' => 'https://www.spa-us.com/',
            'internationalPhoneNumber' => '+1 803-555-0100',
            'addressComponents' => [['longText' => 'United States', 'shortText' => 'US', 'types' => ['country', 'political']]],
        ]),
    ]])]);
    $searchJob = searchJobWithPlacesKey();

    (new SourceSearchJob($searchJob->id))->handle(app(LeadSourceManager::class), app(LeadUpserter::class));

    expect($searchJob->fresh()->results_count)->toBe(1)
        ->and($searchJob->icp->leads()->pluck('country')->all())->toBe(['BR']);
});

test('SourceSearchJob é idempotente: ignora job que não está pendente', function () {
    Http::fake();
    $searchJob = searchJobWithPlacesKey(['status' => SearchJobStatus::Done]);

    (new SourceSearchJob($searchJob->id))->handle(app(LeadSourceManager::class), app(LeadUpserter::class));

    Http::assertNothingSent();
});

test('SourceSearchJob marca failed com a mensagem de erro', function () {
    Http::fake([GooglePlacesDriver::ENDPOINT => Http::response(['error' => ['message' => 'quota exceeded']], 429)]);
    $searchJob = searchJobWithPlacesKey();

    expect(fn () => (new SourceSearchJob($searchJob->id))->handle(app(LeadSourceManager::class), app(LeadUpserter::class)))
        ->toThrow(RuntimeException::class);

    expect($searchJob->fresh()->status)->toBe(SearchJobStatus::Failed)
        ->and($searchJob->fresh()->error)->toContain('quota exceeded');
});

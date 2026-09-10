<?php

use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesDriver;
use App\Domains\LeadSources\LeadSourceManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('lead-sources.drivers.google_places', ['enabled' => true, 'key' => 'test-key', 'max_pages' => 3, 'requests_per_second' => 100]);
});

test('pagina até o nextPageToken acabar e normaliza o lead', function () {
    Http::fake([
        GooglePlacesDriver::ENDPOINT => Http::sequence()
            ->push(['places' => [placePayload('p1'), placePayload('p2')], 'nextPageToken' => 'tok'])
            ->push(['places' => [placePayload('p3', ['websiteUri' => 'https://instagram.com/spa'])]]),
    ]);

    $driver = app(LeadSourceManager::class)->driver('google_places');
    $leads = iterator_to_array($driver->search([['text' => 'spa in São Paulo', 'region_code' => null]]), false);

    expect($leads)->toHaveCount(3)
        ->and($leads[0]->externalId)->toBe('p1')
        ->and($leads[0]->domain)->toBe('spa-p1.com.br')
        ->and($leads[0]->phone)->toBe('551133334444')
        ->and($leads[0]->country)->toBe('BR')
        ->and($leads[0]->city)->toBe('São Paulo')
        ->and($leads[2]->domain)->toBeNull()
        ->and($leads[0]->rawPayload['place']['rating'])->toBe(4.6);

    Http::assertSentCount(2);
    Http::assertSent(fn ($req) => $req->hasHeader('X-Goog-Api-Key', 'test-key') && $req['textQuery'] === 'spa in São Paulo' && ! isset($req['pageToken']));
    Http::assertSent(fn ($req) => ($req['pageToken'] ?? null) === 'tok');
});

test('respeita max_pages', function () {
    config()->set('lead-sources.drivers.google_places.max_pages', 1);
    Http::fake([GooglePlacesDriver::ENDPOINT => Http::response(['places' => [placePayload('p1')], 'nextPageToken' => 'tok'])]);

    $driver = app(LeadSourceManager::class)->driver('google_places');
    iterator_to_array($driver->search([['text' => 'spa', 'region_code' => 'br']]), false);

    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => $req['regionCode'] === 'br' && $req['languageCode'] === 'pt-BR');
});

test('lança exceção com a mensagem da API em erro', function () {
    Http::fake([GooglePlacesDriver::ENDPOINT => Http::response(['error' => ['message' => 'API key not valid']], 403)]);

    $driver = app(LeadSourceManager::class)->driver('google_places');

    expect(fn () => iterator_to_array($driver->search([['text' => 'spa']]), false))
        ->toThrow(RuntimeException::class, 'API key not valid');
});

test('manager lista só drivers habilitados e implementados', function () {
    config()->set('lead-sources.drivers.apollo.enabled', false);
    config()->set('lead-sources.drivers.hunter', ['enabled' => true]); // no createHunterDriver → ignored

    expect(app(LeadSourceManager::class)->enabledDrivers())->toBe(['google_places']);
});

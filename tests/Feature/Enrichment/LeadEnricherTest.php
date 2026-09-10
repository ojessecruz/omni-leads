<?php

use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\Enrichment\LeadEnricher;
use App\Jobs\EnrichLeadJob;
use App\Jobs\ScoreLeadJob;
use App\Models\Icp;
use App\Models\Lead;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config()->set('enrichment', [
        'ttl_days' => 30,
        'site_scrape' => ['enabled' => true, 'timeout' => 5, 'paths' => ['/', '/contato']],
        'hunter' => ['enabled' => true, 'key' => 'hk', 'requests_per_minute' => 100],
        'evolution' => ['enabled' => true, 'base_url' => 'https://evo.test', 'instance' => 'main', 'key' => 'ek', 'requests_per_minute' => 100],
    ]);
});

test('scrape do site acha mailto e telefone e não chama o Hunter', function () {
    Http::fake([
        'https://spa.com.br/' => Http::response('<html><a href="mailto:Contato@spa.com.br?subject=oi">fale</a> <a href="https://wa.me/5511987654321">zap</a> <img src="logo@2x.png"></html>', 200, ['Content-Type' => 'text/html']),
        'https://evo.test/*' => Http::response([['jid' => '5511987654321@s.whatsapp.net', 'exists' => true, 'number' => '5511987654321']]),
    ]);

    $lead = Lead::factory()->create(['domain' => 'spa.com.br', 'phone' => null, 'email' => null]);

    $ran = app(LeadEnricher::class)->enrich($lead);

    $lead->refresh();

    expect($ran)->toBe(['site_scrape', 'evolution'])
        ->and($lead->email)->toBe('contato@spa.com.br')
        ->and($lead->phone)->toBe('5511987654321')
        ->and($lead->whatsapp_valid)->toBeTrue()
        ->and($lead->enriched_at)->not->toBeNull()
        ->and($lead->sources()->orderBy('id')->pluck('driver')->all())->toBe(['site_scrape', 'evolution'])
        ->and($lead->sources()->where('driver', 'site_scrape')->first()->raw_payload['emails'])->toBe(['contato@spa.com.br']);

    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'hunter.io'));
});

test('sem e-mail no site cai no Hunter e prioriza cargo do ICP', function () {
    Http::fake([
        'https://spa.com.br/*' => Http::response('<html>sem contato</html>', 200, ['Content-Type' => 'text/html']),
        HunterClient::ENDPOINT.'*' => Http::response(['data' => ['organization' => 'Spa', 'emails' => [
            ['value' => 'rh@spa.com.br', 'type' => 'generic', 'confidence' => 95, 'position' => 'HR'],
            ['value' => 'ana@spa.com.br', 'type' => 'personal', 'confidence' => 80, 'position' => 'Owner & Founder'],
        ]]]),
    ]);

    $icp = Icp::factory()->create(['criteria' => ['keywords' => ['spa'], 'countries' => ['BR'], 'job_titles' => ['owner']]]);
    $lead = Lead::factory()->for($icp)->create(['domain' => 'spa.com.br', 'phone' => null, 'email' => null]);

    app(LeadEnricher::class)->enrich($lead);

    expect($lead->fresh()->email)->toBe('ana@spa.com.br')
        ->and($lead->sources()->where('driver', 'hunter')->first()->raw_payload['emails'])->toHaveCount(2);
});

test('falha em um passo não bloqueia os outros e fica registrada', function () {
    Http::fake([
        'https://spa.com.br/*' => Http::response('<html>nada</html>', 200, ['Content-Type' => 'text/html']),
        HunterClient::ENDPOINT.'*' => Http::response(['errors' => [['details' => 'No credits']]], 429),
        'https://evo.test/*' => Http::response([['exists' => false]]),
    ]);

    $lead = Lead::factory()->create(['domain' => 'spa.com.br', 'phone' => '5511999990000', 'email' => null]);

    $ran = app(LeadEnricher::class)->enrich($lead);

    expect($ran)->toBe(['site_scrape', 'hunter', 'evolution'])
        ->and($lead->fresh()->whatsapp_valid)->toBeFalse()
        ->and($lead->sources()->where('driver', 'hunter')->first()->raw_payload['error'])->toContain('No credits');
});

test('site inacessível registra erro e segue', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);
    config()->set('enrichment.hunter.enabled', false);
    config()->set('enrichment.evolution.enabled', false);

    $lead = Lead::factory()->create(['domain' => 'off.com.br', 'email' => null]);

    app(LeadEnricher::class)->enrich($lead);

    expect($lead->sources()->where('driver', 'site_scrape')->first()->raw_payload['error'])->toBe('site unreachable');
});

test('passos desabilitados ou sem dado são pulados', function () {
    Http::fake();
    config()->set('enrichment.hunter.enabled', false);

    $lead = Lead::factory()->create(['domain' => null, 'phone' => null, 'email' => null]);

    expect(app(LeadEnricher::class)->enrich($lead))->toBe([]);
    Http::assertNothingSent();
});

test('EnrichLeadJob respeita o TTL e sempre dispara o score', function () {
    Queue::fake([ScoreLeadJob::class]);
    Http::fake();

    $lead = Lead::factory()->create(['domain' => 'spa.com.br', 'email' => null, 'enriched_at' => now()->subDay()]);

    (new EnrichLeadJob($lead->id))->handle(app(LeadEnricher::class));

    Http::assertNothingSent();
    Queue::assertPushed(ScoreLeadJob::class, fn ($j) => $j->leadId === $lead->id && $j->queue === 'scoring');

    (new EnrichLeadJob($lead->id, force: true))->handle(app(LeadEnricher::class));

    Http::assertSent(fn ($req) => str_contains($req->url(), 'spa.com.br'));
});

test('limiter de enriquecimento usa o menor RPM dos clientes habilitados e é livre só com site scrape', function () {
    config()->set('enrichment.hunter', ['enabled' => true, 'key' => 'k', 'requests_per_minute' => 15]);
    config()->set('enrichment.evolution', ['enabled' => true, 'base_url' => 'https://evo.test', 'instance' => 'i', 'key' => 'k', 'requests_per_minute' => 60]);

    $limiter = RateLimiter::limiter('enrichment');
    $job = new EnrichLeadJob(1);

    expect($limiter($job)->maxAttempts)->toBe(15)
        ->and(collect($job->middleware())->first())->toBeInstanceOf(RateLimited::class);

    config()->set('enrichment.hunter.enabled', false);
    expect($limiter($job)->maxAttempts)->toBe(60);

    config()->set('enrichment.evolution.enabled', false);
    expect($limiter($job))->toBeInstanceOf(Unlimited::class);
});

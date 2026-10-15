<?php

use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\Enrichment\LeadEnricher;
use App\Enums\IntegrationProvider;
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
        'hunter' => ['requests_per_minute' => 100],
        'evolution' => ['requests_per_minute' => 100],
    ]);
});

/** Lead whose project owner connected Hunter and Evolution, unless told otherwise. */
function enrichableLead(array $attributes = [], array $providers = [IntegrationProvider::Hunter, IntegrationProvider::Evolution], ?Icp $icp = null): Lead
{
    $lead = Lead::factory()->for($icp ?? Icp::factory())->create($attributes);

    foreach ($providers as $provider) {
        connectIntegration($lead->project->user, $provider);
    }

    return $lead;
}

test('scrape do site acha mailto e telefone e não chama o Hunter', function () {
    Http::fake([
        'https://spa.com.br/' => Http::response('<html><a href="mailto:Contato@spa.com.br?subject=oi">fale</a> <a href="https://wa.me/5511987654321">zap</a> <img src="logo@2x.png"></html>', 200, ['Content-Type' => 'text/html']),
        'https://evo.test/*' => Http::response([['jid' => '5511987654321@s.whatsapp.net', 'exists' => true, 'number' => '5511987654321']]),
    ]);

    $lead = enrichableLead(['domain' => 'spa.com.br', 'phone' => null, 'email' => null]);

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
    $lead = enrichableLead(['domain' => 'spa.com.br', 'phone' => null, 'email' => null], icp: $icp);

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

    $lead = enrichableLead(['domain' => 'spa.com.br', 'phone' => '5511999990000', 'email' => null]);

    $ran = app(LeadEnricher::class)->enrich($lead);

    expect($ran)->toBe(['site_scrape', 'hunter', 'evolution'])
        ->and($lead->fresh()->whatsapp_valid)->toBeFalse()
        ->and($lead->sources()->where('driver', 'hunter')->first()->raw_payload['error'])->toContain('No credits');
});

test('site inacessível registra erro e segue', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);
    $lead = enrichableLead(['domain' => 'off.com.br', 'email' => null], providers: []);

    app(LeadEnricher::class)->enrich($lead);

    expect($lead->sources()->where('driver', 'site_scrape')->first()->raw_payload['error'])->toBe('site unreachable');
});

test('passos sem dado são pulados', function () {
    Http::fake();

    $lead = enrichableLead(['domain' => null, 'phone' => null, 'email' => null]);

    expect(app(LeadEnricher::class)->enrich($lead))->toBe([]);
    Http::assertNothingSent();
});

test('Hunter e Evolution só rodam com a chave do dono do projeto, que vai na requisição', function () {
    Http::fake([
        'https://spa.com.br/*' => Http::response('<html>nada</html>', 200, ['Content-Type' => 'text/html']),
        HunterClient::ENDPOINT.'*' => Http::response(['data' => ['emails' => []]]),
    ]);

    $without = enrichableLead(['domain' => 'spa.com.br', 'phone' => '5511999990000', 'email' => null], providers: []);
    expect(app(LeadEnricher::class)->enrich($without))->toBe(['site_scrape']);

    $with = enrichableLead(['domain' => 'spa.com.br', 'phone' => null, 'email' => null], providers: []);
    connectIntegration($with->project->user, IntegrationProvider::Hunter, ['key' => 'hunter-do-dono']);
    expect(app(LeadEnricher::class)->enrich($with))->toBe(['site_scrape', 'hunter']);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'hunter.io') && $req['api_key'] === 'hunter-do-dono');
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

test('limiter de enriquecimento usa o menor RPM dos clientes do dono e é livre só com site scrape', function () {
    config()->set('enrichment.hunter.requests_per_minute', 15);
    config()->set('enrichment.evolution.requests_per_minute', 60);

    $limiter = RateLimiter::limiter('enrichment');
    $both = new EnrichLeadJob(enrichableLead()->id);

    expect($limiter($both)->maxAttempts)->toBe(15)
        ->and(collect($both->middleware())->first())->toBeInstanceOf(RateLimited::class)
        ->and($limiter(new EnrichLeadJob(enrichableLead(providers: [IntegrationProvider::Evolution])->id))->maxAttempts)->toBe(60)
        ->and($limiter(new EnrichLeadJob(enrichableLead(providers: [])->id)))->toBeInstanceOf(Unlimited::class);
});

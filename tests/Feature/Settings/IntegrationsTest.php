<?php

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesDriver;
use App\Enums\IntegrationProvider;
use App\Jobs\SendWhatsAppJob;
use App\Livewire\Settings\Integrations;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\OutboundConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('página lista os provedores e marca o Google Places como obrigatório', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('integrations.edit'))
        ->assertOk()
        ->assertSeeInOrder(['Google Places', 'Required', 'Apollo', 'Hunter', 'Evolution API', 'Saleshandy']);
});

test('salvar verifica a chave no provedor e guarda criptografada na conta', function () {
    Http::fake([GooglePlacesDriver::ENDPOINT => Http::response(['places' => [['id' => 'x']]])]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Integrations::class)
        ->set('form.google_places.key', ' gp-secret-1234 ')
        ->call('save', 'google_places')
        ->assertHasNoErrors()
        ->assertSet('form.google_places.key', '')
        ->assertSee('••••1234');

    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Goog-Api-Key', 'gp-secret-1234') && $r->hasHeader('X-Goog-FieldMask', 'places.id'));
    expect($user->fresh()->credentialsFor(IntegrationProvider::GooglePlaces))->toBe(['key' => 'gp-secret-1234'])
        ->and(DB::table('integrations')->value('credentials'))->not->toContain('gp-secret-1234');
});

test('chave recusada não é salva e o erro aparece no campo', function () {
    Http::fake([HunterClient::ACCOUNT_ENDPOINT.'*' => Http::response(['errors' => [['details' => 'No user found for the API key supplied']]], 401)]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Integrations::class)
        ->set('form.hunter.key', 'errada')
        ->call('save', 'hunter')
        ->assertHasErrors('form.hunter.key');

    expect($user->integrations()->count())->toBe(0);
});

test('Evolution pede só servidor e chave global; chave em branco mantém a salva', function () {
    Http::fake(['https://evo.test/instance/fetchInstances' => Http::response([])]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Integrations::class)
        ->set('form.evolution.base_url', 'https://evo.test/')
        ->call('save', 'evolution')
        ->assertHasErrors(['form.evolution.key'])
        ->set('form.evolution.key', 'global-key')
        ->call('save', 'evolution')
        ->assertHasNoErrors()
        ->assertSee('Connect phone')
        ->set('form.evolution.base_url', 'https://evo.test')
        ->call('save', 'evolution')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://evo.test/instance/fetchInstances' && $r->hasHeader('apikey', 'global-key'));
    expect($user->fresh()->integration(IntegrationProvider::Evolution)->credentials)->toBe(['mode' => 'own', 'base_url' => 'https://evo.test', 'key' => 'global-key'])
        // without a paired phone, WhatsApp is not usable yet
        ->and($user->fresh()->hasIntegration(IntegrationProvider::Evolution))->toBeFalse();
});

test('conectar celular cria a instância, mostra o QR e marca pareado quando o estado fica open', function () {
    Http::fake([
        'https://evo.test/instance/create' => Http::response(['instance' => ['instanceName' => 'x', 'status' => 'connecting'], 'hash' => 'T', 'qrcode' => ['base64' => 'data:image/png;base64,QR1']], 201),
        'https://evo.test/instance/connectionState/*' => Http::sequence()
            ->push(['instance' => ['state' => 'connecting']])
            ->push(['instance' => ['state' => 'open']]),
        'https://evo.test/instance/fetchInstances*' => Http::response([['name' => 'x', 'ownerJid' => '5511988887777@s.whatsapp.net']]),
    ]);
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution, ['base_url' => 'https://evo.test', 'key' => 'global-key']);

    $page = Livewire::actingAs($user)->test(Integrations::class)
        ->call('connectPhone')
        ->assertSet('whatsappQr', 'data:image/png;base64,QR1')
        ->assertSeeHtml('wire:poll.3s="checkPhone"')
        ->call('checkPhone')
        ->assertSet('whatsappQr', 'data:image/png;base64,QR1');

    expect($user->fresh()->hasIntegration(IntegrationProvider::Evolution))->toBeFalse();

    $page->call('checkPhone')
        ->assertSet('whatsappQr', null)
        ->assertSee('WhatsApp connected · +5511988887777');

    $credentials = $user->fresh()->integration(IntegrationProvider::Evolution)->credentials;
    expect($credentials['instance'])->toStartWith("omnileads-{$user->id}-")
        ->and($credentials['phone'])->toBe('5511988887777')
        ->and($user->fresh()->hasIntegration(IntegrationProvider::Evolution))->toBeTrue();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://evo.test/instance/create'
        && $r['instanceName'] === $credentials['instance'] && $r['qrcode'] === true && $r['integration'] === 'WHATSAPP-BAILEYS' && $r->hasHeader('apikey', 'global-key'));
});

test('desconectar celular faz logout e mantém a instância para parear de novo', function () {
    Http::fake(['https://evo.test/instance/logout/main' => Http::response(['status' => 'SUCCESS'])]);
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);

    Livewire::actingAs($user)->test(Integrations::class)
        ->call('disconnectPhone')
        ->assertSee('Connect phone');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://evo.test/instance/logout/main');
    expect($user->fresh()->integration(IntegrationProvider::Evolution)->credentials)->toBe(['base_url' => 'https://evo.test', 'key' => 'ek', 'instance' => 'main'])
        ->and($user->fresh()->hasIntegration(IntegrationProvider::Evolution))->toBeFalse();
});

test('trocar o servidor do Evolution descarta o celular pareado no servidor antigo', function () {
    Http::fake([
        'https://novo.test/instance/fetchInstances' => Http::response([]),
        'https://evo.test/instance/delete/main' => Http::response(['status' => 'SUCCESS']),
    ]);
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);

    Livewire::actingAs($user)->test(Integrations::class)
        ->set('form.evolution.base_url', 'https://novo.test')
        ->call('save', 'evolution')
        ->assertHasNoErrors();

    expect($user->fresh()->integration(IntegrationProvider::Evolution)->credentials)->toBe(['mode' => 'own', 'base_url' => 'https://novo.test', 'key' => 'ek']);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://evo.test/instance/delete/main');
});

test('sem servidor do Omni Leads configurado, só existe a opção de servidor próprio', function () {
    Livewire::actingAs(User::factory()->create())->test(Integrations::class)
        ->assertSet('form.evolution.mode', 'own')
        ->assertDontSee('Omni Leads server')
        ->set('form.evolution.mode', 'hosted')
        ->call('save', 'evolution')
        ->assertHasErrors('form.evolution.mode');
});

test('servidor do Omni Leads: sem URL nem chave, pareia no servidor da plataforma e é usado no envio', function () {
    config()->set('enrichment.evolution.base_url', 'https://omni-evo.test');
    config()->set('enrichment.evolution.key', 'omni-global');
    Http::fake([
        'https://omni-evo.test/instance/create' => Http::response(['qrcode' => ['base64' => 'data:image/png;base64,QR']], 201),
        'https://omni-evo.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
        'https://omni-evo.test/instance/fetchInstances*' => Http::response([['ownerJid' => '5511900001111@s.whatsapp.net']]),
        'https://omni-evo.test/message/sendText/*' => Http::response(['key' => ['id' => 'M1']]),
    ]);
    $lead = Lead::factory()->create(['phone' => '5511999990000']);
    $user = $lead->project->user;

    Livewire::actingAs($user)->test(Integrations::class)
        ->assertSet('form.evolution.mode', 'hosted')
        ->assertSee('Omni Leads server')
        ->call('save', 'evolution')
        ->assertHasNoErrors()
        ->call('connectPhone')
        ->call('checkPhone')
        ->assertSee('WhatsApp connected · +5511900001111');

    $credentials = $user->fresh()->integration(IntegrationProvider::Evolution)->credentials;
    expect($credentials)->not->toHaveKeys(['base_url', 'key'])
        ->and($credentials['mode'])->toBe('hosted')
        ->and($user->fresh()->hasIntegration(IntegrationProvider::Evolution))->toBeTrue();

    (new SendWhatsAppJob($lead->id, 'Oi', 'd-1'))->handle(app(EvolutionClient::class));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://omni-evo.test/instance/create' && $r->hasHeader('apikey', 'omni-global'));
    Http::assertSent(fn (Request $r) => $r->url() === "https://omni-evo.test/message/sendText/{$credentials['instance']}");
});

test('trocar do servidor próprio para o do Omni Leads apaga a instância antiga e pede novo pareamento', function () {
    config()->set('enrichment.evolution.base_url', 'https://omni-evo.test');
    config()->set('enrichment.evolution.key', 'omni-global');
    Http::fake(['https://evo.test/instance/delete/main' => Http::response(['status' => 'SUCCESS'])]);
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);

    Livewire::actingAs($user)->test(Integrations::class)
        ->assertSet('form.evolution.mode', 'own')
        ->set('form.evolution.mode', 'hosted')
        ->call('save', 'evolution')
        ->assertSee('Connect phone');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://evo.test/instance/delete/main');
    expect($user->fresh()->integration(IntegrationProvider::Evolution)->credentials)->toBe(['mode' => 'hosted']);
});

test('remover o Evolution apaga a instância no servidor', function () {
    Http::fake(['https://evo.test/instance/delete/main' => Http::response(['status' => 'SUCCESS'])]);
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);

    Livewire::actingAs($user)->test(Integrations::class)->call('remove', 'evolution');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://evo.test/instance/delete/main');
    expect($user->integrations()->count())->toBe(0);
});

test('remover a chave do Saleshandy desliga o conector dos projetos do usuário', function () {
    $connection = OutboundConnection::factory()->create();
    $user = $connection->project->user;
    $foreign = OutboundConnection::factory()->create();

    Livewire::actingAs($user)->test(Integrations::class)->call('remove', 'saleshandy');

    expect($user->integrations()->count())->toBe(0)
        ->and(OutboundConnection::pluck('id')->all())->toBe([$foreign->id]);
});

test('provedor desconhecido é recusado e a chave de um usuário não vaza para outro', function () {
    $owner = connectIntegration(User::factory()->create(), IntegrationProvider::Apollo, ['key' => 'apollo-do-dono'])->user;

    Livewire::actingAs(User::factory()->create())->test(Integrations::class)
        ->call('remove', 'apollo')
        ->assertDontSee('apollo-do-dono')
        ->call('save', 'mailchimp')
        ->assertHasErrors('provider');

    expect($owner->fresh()->hasIntegration(IntegrationProvider::Apollo))->toBeTrue()
        ->and(Integration::count())->toBe(1);
});

test('aviso de chave do Google Places some quando a conta tem a chave', function () {
    $project = Project::factory()->create();

    $this->actingAs($project->user)->get(route('projects.show', $project))->assertSee('Searches need your Google Places API key');

    connectIntegration($project->user, IntegrationProvider::GooglePlaces);

    $this->actingAs($project->user)->get(route('projects.show', $project))->assertDontSee('Searches need your Google Places API key');
});

<?php

use App\Domains\Enrichment\Clients\HunterClient;
use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesDriver;
use App\Enums\IntegrationProvider;
use App\Livewire\Settings\Integrations;
use App\Models\Integration;
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

test('Evolution pede servidor, instância e chave; chave em branco mantém a salva', function () {
    Http::fake(['https://evo.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']])]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Integrations::class)
        ->set('form.evolution.base_url', 'https://evo.test/')
        ->call('save', 'evolution')
        ->assertHasErrors(['form.evolution.instance', 'form.evolution.key'])
        ->set('form.evolution.instance', 'main')
        ->set('form.evolution.key', 'ek')
        ->call('save', 'evolution')
        ->assertHasNoErrors()
        ->set('form.evolution.instance', 'outra')
        ->call('save', 'evolution')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://evo.test/instance/connectionState/main' && $r->hasHeader('apikey', 'ek'));
    expect($user->fresh()->credentialsFor(IntegrationProvider::Evolution))->toBe(['base_url' => 'https://evo.test/', 'instance' => 'outra', 'key' => 'ek']);
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

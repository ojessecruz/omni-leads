<?php

use App\Domains\Onboarding\OnboardingChecklist;
use App\Domains\Onboarding\OnboardingStep;
use App\Enums\IntegrationProvider;
use App\Livewire\Dashboard\Onboarding;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SearchJob;
use App\Models\User;
use Livewire\Livewire;

/** @return array<string, bool> step key => done */
function onboardingProgress(User $user): array
{
    return collect(app(OnboardingChecklist::class)->steps($user->fresh()))
        ->mapWithKeys(fn (OnboardingStep $s) => [$s->key => $s->done])
        ->all();
}

test('conta nova começa com todos os passos pendentes', function () {
    $user = User::factory()->create();

    expect(onboardingProgress($user))->toBe([
        'places_key' => false, 'project' => false, 'search' => false, 'leads' => false, 'outbound' => false,
    ]);
});

test('cada passo é marcado a partir dos dados do usuário', function () {
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::GooglePlaces, ['key' => 'k']);
    $icp = Icp::factory()->for(Project::factory()->for($user))->create();
    SearchJob::factory()->for($icp)->create();
    Lead::factory()->for($icp)->create();
    connectIntegration($user, IntegrationProvider::Saleshandy, ['key' => 'k']);

    expect(onboardingProgress($user))->each->toBeTrue();
});

test('dados de outro usuário não contam', function () {
    $user = User::factory()->create();
    Lead::factory()->create();
    SearchJob::factory()->create();

    expect(onboardingProgress($user))->toMatchArray(['project' => false, 'search' => false, 'leads' => false]);
});

test('o passo do ICP leva ao wizard do projeto mais recente', function () {
    $user = User::factory()->create();
    Project::factory()->for($user)->create();
    $latest = Project::factory()->for($user)->create();

    $step = collect(app(OnboardingChecklist::class)->steps($user))->firstWhere('key', 'search');

    expect($step->url)->toBe(route('icps.create', $latest));
});

test('dashboard mostra os primeiros passos para conta nova', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['First steps', 'Connect Google Places', 'Create a project', 'Define the ICP and run the search', 'Review your first leads', 'Connect an outbound channel']);
});

test('o painel some quando os passos obrigatórios estão feitos, mesmo sem canal de saída', function () {
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::GooglePlaces, ['key' => 'k']);
    $icp = Icp::factory()->for(Project::factory()->for($user))->create();
    SearchJob::factory()->for($icp)->create();
    Lead::factory()->for($icp)->create();

    Livewire::actingAs($user)->test(Onboarding::class)->assertDontSee('First steps');
});

test('dispensar esconde o painel e fica gravado', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Onboarding::class)
        ->assertSee('First steps')
        ->call('dismiss')
        ->assertDontSee('First steps');

    expect($user->fresh()->onboarding_dismissed_at)->not->toBeNull();

    $this->actingAs($user->fresh())->get(route('dashboard'))
        ->assertDontSee('First steps')
        ->assertSee('Start by creating a project');
});

test('integrações mostram o guia da chave do Google Places, aberto enquanto não há chave', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('integrations.edit', ['open' => 'google_places']))
        ->assertSee('id="google-places-guide"', false)
        ->assertSee('places.googleapis.com')
        ->assertSee('How to get your key');

    connectIntegration($user, IntegrationProvider::GooglePlaces, ['key' => 'k']);

    $html = $this->actingAs($user->fresh())->get(route('integrations.edit', ['open' => 'google_places']))->getContent();
    expect($html)->toMatch('/<details id="google-places-guide"[^>]*>/')
        ->not->toMatch('/<details id="google-places-guide"[^>]*\sopen[\s>]/');
});

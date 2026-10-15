<?php

use App\Enums\Theme;
use App\Livewire\Settings\Appearance;
use App\Models\User;
use Livewire\Livewire;

test('usuário novo usa o tema escuro', function () {
    $user = User::factory()->create();

    expect($user->theme)->toBe(Theme::Dark);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-theme="dark"', false)
        ->assertDontSee('data-theme-system', false);
});

test('aparência salva o tema e o app passa a usar o claro', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Appearance::class)
        ->assertSet('theme', 'dark')
        ->set('theme', 'light')
        ->assertHasNoErrors()
        ->assertRedirect(route('appearance.edit'));

    expect($user->fresh()->theme)->toBe(Theme::Light);

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertSee('data-theme="light"', false);
});

test('tema do sistema marca o html e segue o sistema operacional por script', function () {
    $user = User::factory()->create(['theme' => Theme::System]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('data-theme-system', false)
        ->assertSee("matchMedia('(prefers-color-scheme: light)')", false);
});

test('aparência rejeita tema inválido', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Appearance::class)
        ->set('theme', 'sepia')
        ->assertHasErrors('theme');

    expect($user->fresh()->theme)->toBe(Theme::Dark);
});

test('site continua escuro mesmo para quem usa o tema claro', function () {
    $user = User::factory()->create(['theme' => Theme::Light]);

    $this->actingAs($user)->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-theme="light"', false);
});

test('seletor do header troca o tema e volta para a mesma página', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertSee(route('theme.update'));

    $this->actingAs($user)
        ->from(route('leads.index'))
        ->post(route('theme.update'), ['theme' => 'system'])
        ->assertRedirect(route('leads.index'));

    expect($user->fresh()->theme)->toBe(Theme::System);
});

test('seletor do header rejeita tema inválido e exige login', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from(route('dashboard'))
        ->post(route('theme.update'), ['theme' => 'sepia'])
        ->assertSessionHasErrors('theme');

    expect($user->fresh()->theme)->toBe(Theme::Dark);

    auth()->logout();
    $this->post(route('theme.update'), ['theme' => 'light'])->assertRedirect(route('login'));
});

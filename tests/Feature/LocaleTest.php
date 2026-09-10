<?php

use App\Livewire\Icps\Wizard;
use App\Livewire\Settings\Appearance;
use App\Models\Project;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

test('padrão é inglês; usuário com locale vê a interface traduzida', function () {
    $user = User::factory()->create();
    Project::factory()->for($user)->create();

    $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertSee('New project');

    $user->update(['locale' => 'pt_BR']);
    $this->actingAs($user)->get(route('projects.index'))->assertSee('Novo projeto');

    $user->update(['locale' => 'es']);
    $this->actingAs($user)->get(route('projects.index'))->assertSee('Nuevo proyecto');
});

test('visitante recebe o idioma do navegador', function () {
    $this->withHeader('Accept-Language', 'es-MX,es;q=0.9')->get(route('login'))->assertSee('Iniciar sesión');
    $this->withHeader('Accept-Language', 'pt-BR')->get(route('login'))->assertSee('Entrar');
    $this->withHeader('Accept-Language', 'fr')->get(route('login'))->assertSee('Log in');
});

test('seletor de idioma salva no usuário e rejeita valor inválido', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Appearance::class)
        ->assertSet('locale', 'en')
        ->set('locale', 'pt_BR');

    expect($user->fresh()->locale)->toBe('pt_BR');

    Livewire::actingAs($user)->test(Appearance::class)->set('locale', 'fr')->assertHasErrors('locale');
    expect($user->fresh()->locale)->toBe('pt_BR');
});

test('prompt do Ask AI e nomes de país seguem o idioma', function () {
    $user = User::factory()->create(['locale' => 'es']);
    $project = Project::factory()->for($user)->create();
    app()->setLocale('es'); // the middleware does not run under Livewire::test

    Livewire::actingAs($user)->test(Wizard::class, ['project' => $project])
        ->set('mode', 'ask_ai')->set('step', 3)
        ->assertSee('Eres un especialista');

    expect(Countries::name('DE'))->toBe('Alemania');
    app()->setLocale('en');
    expect(Countries::name('DE'))->toBe('Germany')
        ->and(array_key_first(Countries::options()))->toBe('AO');
});

test('todas as chaves usadas têm tradução em pt_BR e es', function () {
    $pattern = '/(?:__|trans_choice)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/';
    $files = array_merge(
        File::allFiles(resource_path('views')),
        File::allFiles(app_path()),
    );
    $keys = [];

    foreach ($files as $file) {
        preg_match_all($pattern, $file->getContents(), $m);
        foreach ($m[2] as $key) {
            $keys[] = str_replace("\\'", "'", $key);
        }
    }

    foreach (['pt_BR', 'es'] as $locale) {
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        $missing = array_values(array_diff(array_unique($keys), array_keys($translations)));

        expect($missing)->toBe([], "Faltam traduções em {$locale}: ".implode(', ', $missing));
    }
});

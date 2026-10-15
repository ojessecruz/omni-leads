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

test('switcher do visitante guarda o idioma na sessão e vence o navegador', function () {
    $this->withHeader('Accept-Language', 'en-US')
        ->from(route('home'))
        ->post(route('locale.update'), ['locale' => 'pt_BR'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('locale', 'pt_BR');

    $this->withHeader('Accept-Language', 'en-US')->get(route('login'))->assertSee('Entrar');
});

test('switcher do usuário logado salva na conta e limpa a sessão', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->withSession(['locale' => 'es'])
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'pt_BR'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionMissing('locale');

    expect($user->fresh()->locale)->toBe('pt_BR');
});

test('switcher rejeita idioma não suportado', function () {
    $this->from(route('home'))->post(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale')
        ->assertSessionMissing('locale');
});

test('switcher aparece na landing, no login e no app', function () {
    $this->get(route('home'))->assertSee(route('locale.update'));
    $this->get(route('login'))->assertSee(route('locale.update'));

    $user = User::factory()->create(['locale' => 'pt_BR']);
    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee(route('locale.update'))
        ->assertSee('PT');
});

test('mensagens de autenticação seguem o idioma', function () {
    $this->withHeader('Accept-Language', 'pt-BR')
        ->from(route('login'))
        ->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'Essas credenciais não conferem.']);
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

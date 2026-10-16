<?php

use App\Models\User;

/** Boots the app again with PASSKEYS_ENABLED switched, as in production (off). */
function bootWithPasskeys(bool $enabled): void
{
    $value = $enabled ? 'true' : 'false';
    putenv("PASSKEYS_ENABLED={$value}");
    $_ENV['PASSKEYS_ENABLED'] = $_SERVER['PASSKEYS_ENABLED'] = $value;

    test()->refreshApplication();
}

afterEach(fn () => bootWithPasskeys(true));

test('com passkeys desligadas o login só tem e-mail e senha e as rotas não existem', function () {
    bootWithPasskeys(false);

    $this->get(route('login'))->assertOk()
        ->assertDontSee('Sign in with a passkey')
        ->assertDontSee('passkeys.js', false);

    $this->get('/passkeys/login/options')->assertNotFound();
    $this->post('/passkeys/login')->assertNotFound();
    $this->get('/.well-known/passkey-endpoints')->assertNotFound();
});

// Unsaved user: after refreshApplication the new connection is outside RefreshDatabase's transaction.
test('com passkeys desligadas a tela de segurança não oferece cadastrar passkey', function () {
    bootWithPasskeys(false);

    $this->actingAs(User::factory()->make(['id' => 999]))
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertDontSee('Add a passkey to sign in without a password');
});

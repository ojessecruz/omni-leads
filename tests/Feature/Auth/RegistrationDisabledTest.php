<?php

/** Boots the app again with REGISTRATION_ENABLED off, as in production. */
function bootWithRegistration(bool $enabled): void
{
    $value = $enabled ? 'true' : 'false';
    putenv("REGISTRATION_ENABLED={$value}");
    $_ENV['REGISTRATION_ENABLED'] = $_SERVER['REGISTRATION_ENABLED'] = $value;

    test()->refreshApplication();
}

afterEach(fn () => bootWithRegistration(true));

test('com o cadastro fechado não há rota de registro nem link na LP e no login', function () {
    bootWithRegistration(false);

    $this->get('/register')->assertNotFound();

    $this->get('/')->assertOk()
        ->assertDontSee('/register', false)
        ->assertDontSee('Start free')
        ->assertSee(route('login'), false);

    $this->get(route('login'))->assertOk()->assertDontSee('Sign up');
});

<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

test('toda regra de validação do framework tem mensagem em pt_BR e es', function (string $locale) {
    $framework = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $translated = require lang_path("{$locale}/validation.php");

    $expected = array_keys(Arr::dot(Arr::except($framework, ['custom', 'attributes'])));
    $missing = array_values(array_diff($expected, array_keys(Arr::dot(Arr::except($translated, ['attributes'])))));

    expect($missing)->toBe([]);
})->with(['pt_BR', 'es']);

test('regras de senha aparecem traduzidas e com o campo no início da frase', function () {
    app()->setLocale('pt_BR');

    $errors = Validator::make(['password' => 'semsimbolo1A'], ['password' => [Password::min(8)->symbols()]])->errors();

    expect($errors->first('password'))->toBe('A senha deve ter pelo menos um símbolo (como ! @ # $).');
});

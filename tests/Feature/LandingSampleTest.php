<?php

test('exemplo da landing segue o idioma do visitante', function (string $locale, string $lead, string $place, string $absent) {
    $this->withSession(['locale' => $locale])
        ->get('/')
        ->assertOk()
        ->assertSee($lead)
        ->assertSee($place)
        ->assertDontSee($absent);
})->with([
    'inglês → EUA' => ['en', 'Bella Aesthetics Studio', 'Austin, US', 'bellaestetica.com.br'],
    'português → Brasil' => ['pt_BR', 'Clínica Bella Estética', 'São Paulo, BR', 'bellaaesthetics.com'],
    'espanhol → México' => ['es', 'bellaestetica.mx', 'Ciudad de México, MX', 'bellaestetica.com.br'],
]);

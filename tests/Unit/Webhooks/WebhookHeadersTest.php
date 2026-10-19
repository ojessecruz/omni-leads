<?php

use App\Domains\Webhooks\WebhookHeaders;

test('lê um cabeçalho por linha e ignora linhas vazias', function () {
    expect(WebhookHeaders::parse("Authorization: Bearer abc:123\n\n  X-Api-Key :  k1  \r\n"))
        ->toBe(['Authorization' => 'Bearer abc:123', 'X-Api-Key' => 'k1'])
        ->and(WebhookHeaders::parse(''))->toBe([]);
});

test('recusa linha sem formato, cabeçalho reservado e excesso', function (string $text, int $code) {
    expect(fn () => WebhookHeaders::parse($text))->toThrow(fn (InvalidArgumentException $e) => expect($e->getCode())->toBe($code));
})->with([
    'sem dois pontos' => ['Authorization Bearer abc', 1],
    'valor vazio' => ['Authorization:', 1],
    'nome inválido' => ['Minha Chave: x', 1],
    'quebra no nome' => ["X-A: 1\nX B: 2", 1],
    'assinatura' => ['X-OmniLeads-Signature: forjada', 2],
    'content-type' => ['content-type: text/plain', 2],
    'demais' => [implode("\n", array_map(fn ($i) => "X-H{$i}: v", range(1, 11))), 3],
]);

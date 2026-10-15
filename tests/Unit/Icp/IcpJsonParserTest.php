<?php

use App\Domains\Icp\Exceptions\IcpParseException;
use App\Domains\Icp\IcpJsonParser;
use App\Enums\CompanySize;

const VALID_JSON = '{"industry":"estética","keywords":["clínica de estética","day spa"],"countries":["br","US"],"regions":["São Paulo"],"company_size":"1-10","job_titles":["owner"],"signals":{"has_website":true,"min_reviews":10,"min_rating":4.0}}';

test('parseia JSON limpo e normaliza', function () {
    $c = (new IcpJsonParser)->parse(VALID_JSON);

    expect($c->industry)->toBe('estética')
        ->and($c->countries)->toBe(['BR', 'US'])
        ->and($c->companySize)->toBe(CompanySize::Micro)
        ->and($c->minReviews)->toBe(10)
        ->and($c->minRating)->toBe(4.0);
});

test('remove fences de markdown e texto em volta', function () {
    $input = "Claro! Aqui está o ICP:\n\n```json\n".VALID_JSON."\n```\n\nQualquer dúvida, me avise.";

    expect((new IcpJsonParser)->parse($input)->keywords)->toBe(['clínica de estética', 'day spa']);
});

test('tolera vírgula sobrando, aspas simples, chaves sem aspas e comentários', function () {
    $input = <<<'TXT'
    {
      industry: 'estética', // segmento
      "keywords": ['spa', 'clínica',],
      "countries": ["Brasil", "United States"],
      "company_size": "11 a 50",
      "signals": { "has_website": "true", "min_reviews": "20", },
    }
    TXT;

    $c = (new IcpJsonParser)->parse($input);

    expect($c->industry)->toBe('estética')
        ->and($c->keywords)->toBe(['spa', 'clínica'])
        ->and($c->countries)->toBe(['BR', 'US'])
        ->and($c->companySize)->toBe(CompanySize::Small)
        ->and($c->hasWebsite)->toBeTrue()
        ->and($c->minReviews)->toBe(20);
});

test('company_size fora do enum vira null sem quebrar', function () {
    $c = (new IcpJsonParser)->parse('{"industry":"x","keywords":["a"],"countries":["BR"],"company_size":"gigante"}');

    expect($c->companySize)->toBeNull();
});

test('erro amigável sem JSON', function () {
    expect(fn () => (new IcpJsonParser)->parse('Desculpe, não posso ajudar.'))
        ->toThrow(IcpParseException::class, 'No JSON found');
});

test('erro amigável quando faltam campos obrigatórios', function () {
    expect(fn () => (new IcpJsonParser)->parse('{"industry":"estética"}'))
        ->toThrow(IcpParseException::class, 'keywords');
});

test('erro amigável em tipo errado', function () {
    expect(fn () => (new IcpJsonParser)->parse('{"industry":"x","keywords":["a"],"countries":["BR"],"signals":{"min_rating":"alta"}}'))
        ->toThrow(IcpParseException::class, 'expected format');
});

test('vazio pede para colar', function () {
    expect(fn () => (new IcpJsonParser)->parse("  \n"))->toThrow(IcpParseException::class, 'Paste the agent answer');
});

test('segmento longo demais vira só a primeira parte em vez de reprovar o JSON', function () {
    $json = json_encode([
        'industry' => 'Beleza e bem-estar, com foco em profissionais autônomos e pequenos estabelecimentos que trabalham com hora marcada e dependem de uma agenda recorrente de clientes.',
        'keywords' => ['barbearia', 'salão de beleza'],
        'countries' => ['BR'],
        'regions' => ['São Paulo, SP', 'Cataguases, MG'],
        'company_size' => '1-10',
        'job_titles' => ['Proprietário de barbearia'],
        'signals' => ['has_website' => false, 'min_reviews' => 10, 'min_rating' => 4.0],
    ]);

    expect((new IcpJsonParser)->parse($json)->industry)->toBe('Beleza e bem-estar')
        ->and((new IcpJsonParser)->parse('{"industry":"'.str_repeat('palavra ', 15).'","keywords":["a"],"countries":["BR"]}')->industry)
        ->toBe(trim(str_repeat('palavra ', 10)));
});

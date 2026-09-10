<?php

use App\Domains\Leads\LeadUpserter;
use App\Domains\LeadSources\Data\LeadDTO;
use App\Models\Icp;

function dto(array $overrides = []): LeadDTO
{
    return new LeadDTO(...array_merge([
        'driver' => 'google_places',
        'externalId' => 'place-1',
        'name' => 'Spa Bella',
        'companyName' => 'Spa Bella',
        'domain' => 'www.SpaBella.com.br',
        'email' => null,
        'phone' => '+55 11 99999-0000',
        'country' => 'br',
        'city' => 'São Paulo',
        'cnpj' => null,
        'rawPayload' => ['place' => ['id' => 'place-1']],
    ], $overrides));
}

test('cria lead normalizado com source', function () {
    $icp = Icp::factory()->create();

    $lead = app(LeadUpserter::class)->upsert($icp, dto());

    expect($lead->domain)->toBe('spabella.com.br')
        ->and($lead->phone)->toBe('5511999990000')
        ->and($lead->country)->toBe('BR')
        ->and($lead->project_id)->toBe($icp->project_id)
        ->and($lead->sources)->toHaveCount(1)
        ->and($lead->sources->first()->external_id)->toBe('place-1');
});

test('faz merge quando o domínio colide: preenche nulos e adiciona source', function () {
    $icp = Icp::factory()->create();
    $upserter = app(LeadUpserter::class);

    $first = $upserter->upsert($icp, dto(['phone' => null, 'city' => null]));
    $second = $upserter->upsert($icp, dto(['externalId' => 'place-2', 'driver' => 'apollo', 'name' => 'Outro nome', 'email' => 'oi@spabella.com.br']));

    expect($second->id)->toBe($first->id)
        ->and($second->name)->toBe('Spa Bella')
        ->and($second->phone)->toBe('5511999990000')
        ->and($second->email)->toBe('oi@spabella.com.br')
        ->and($second->sources()->count())->toBe(2)
        ->and($icp->leads()->count())->toBe(1);
});

test('reprocessar a mesma fonte não duplica', function () {
    $icp = Icp::factory()->create();
    $upserter = app(LeadUpserter::class);

    $upserter->upsert($icp, dto());
    $upserter->upsert($icp, dto());

    expect($icp->leads()->count())->toBe(1)
        ->and($icp->leads()->first()->sources()->count())->toBe(1);
});

test('prioridade do hash: cnpj > domain > phone > email', function () {
    expect(LeadUpserter::hash(['cnpj' => '123', 'domain' => 'a.com', 'phone' => '1', 'email' => 'e']))->toBe(sha1('123'))
        ->and(LeadUpserter::hash(['domain' => 'A.com', 'phone' => '1']))->toBe(sha1('a.com'))
        ->and(LeadUpserter::hash(['phone' => '1', 'email' => 'e']))->toBe(sha1('1'))
        ->and(LeadUpserter::hash(['email' => 'E@x.com']))->toBe(sha1('e@x.com'));
});

test('leads sem identificador forte não colidem entre si', function () {
    $icp = Icp::factory()->create();
    $upserter = app(LeadUpserter::class);

    $upserter->upsert($icp, dto(['externalId' => 'a', 'domain' => null, 'phone' => null]));
    $upserter->upsert($icp, dto(['externalId' => 'b', 'domain' => null, 'phone' => null]));

    expect($icp->leads()->count())->toBe(2);
});

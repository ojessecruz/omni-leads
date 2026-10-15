<?php

use App\Domains\Outbound\Connectors\Saleshandy\SaleshandyConnector;
use App\Domains\Outbound\Data\OutboundProspect;
use App\Domains\Outbound\Exceptions\OutboundException;
use App\Domains\Outbound\OutboundConnectorResolver;
use App\Enums\OutboundEventType;
use App\Models\Lead;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const SH = 'https://open-api.saleshandy.com/v1';

function saleshandy(): SaleshandyConnector
{
    /** @var SaleshandyConnector */
    return app(OutboundConnectorResolver::class)->resolve('saleshandy', ['key' => 'sh-key']);
}

function sequencesResponse(): array
{
    return ['message' => 'ok', 'payload' => [
        ['id' => 'SEQ1', 'title' => 'Clínicas SP', 'active' => true, 'steps' => [['id' => 'STEP1', 'name' => 'Step 1'], ['id' => 'STEP2', 'name' => 'Step 2']]],
        ['id' => 'SEQ2', 'title' => 'Antiga', 'active' => false, 'steps' => []],
    ]];
}

test('resolver devolve o conector pela chave e recusa conector desconhecido', function () {
    expect(saleshandy())->toBeInstanceOf(SaleshandyConnector::class)
        ->and(saleshandy()->key())->toBe('saleshandy');

    app(OutboundConnectorResolver::class)->resolve('mailchimp', ['key' => 'x']);
})->throws(OutboundException::class, 'Unknown outbound connector');

test('verify envia a chave no header e traduz 401 em erro de chave', function () {
    Http::fake([SH.'/*' => Http::response(['message' => 'Unauthorized'], 401)]);

    expect(fn () => saleshandy()->verify())->toThrow(OutboundException::class, 'rejected the API key');

    Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'sh-key') && str_starts_with($r->url(), SH.'/sequences'));
});

test('campanhas vêm das sequências com nome e estado', function () {
    Http::fake([SH.'/sequences*' => Http::response(sequencesResponse())]);

    $campaigns = saleshandy()->campaigns();

    expect($campaigns)->toHaveCount(2)
        ->and($campaigns[0]->toArray())->toBe(['id' => 'SEQ1', 'name' => 'Clínicas SP', 'active' => true])
        ->and($campaigns[1]->active)->toBeFalse();
});

test('push importa no primeiro passo da sequência com os campos por nome', function () {
    Http::fake([
        SH.'/fields*' => Http::response(['payload' => [['id' => 'F1', 'label' => 'Omni Subject'], ['id' => 'F2', 'label' => 'Omni Message']]]),
        SH.'/sequences/prospects/*' => Http::response(['payload' => ['requestId' => 'REQ9']], 201),
        SH.'/sequences*' => Http::response(sequencesResponse()),
    ]);

    $requestId = saleshandy()->push('SEQ1', [
        new OutboundProspect(email: 'ana@clinica.com', firstName: 'Ana', company: 'Clínica Bela', city: 'São Paulo', country: 'BR', subject: 'Oi', message: "Linha 1\n\nLinha 2"),
    ]);

    expect($requestId)->toBe('REQ9');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === SH.'/sequences/prospects/import-with-field-name'
        && $r['stepId'] === 'STEP1'
        && $r['conflictAction'] === 'addMissingFields'
        && $r['prospectList'] === [['Email' => 'ana@clinica.com', 'First Name' => 'Ana', 'Company' => 'Clínica Bela', 'City' => 'São Paulo', 'Country' => 'BR', 'Omni Subject' => 'Oi', 'Omni Message' => "Linha 1\n\nLinha 2"]]);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === SH.'/fields');
});

test('push cria os campos do template que ainda não existem no Saleshandy', function () {
    Http::fake([
        SH.'/fields*' => fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['payload' => [['id' => 'F1', 'label' => 'Omni Subject']]])
            : Http::response(['payload' => ['id' => 'F2']], 201),
        SH.'/sequences/prospects/*' => Http::response(['payload' => ['requestId' => 'REQ9']], 201),
        SH.'/sequences*' => Http::response(sequencesResponse()),
    ]);

    saleshandy()->push('SEQ1', [new OutboundProspect(email: 'a@b.com')]);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), SH.'/fields') && $r['systemFields'] === 'false');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === SH.'/fields' && $r['label'] === 'Omni Message' && $r['fieldType'] === 'long-text');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === SH.'/fields' && $r['label'] === 'Omni Subject');
    expect(saleshandy()->messageTags())->toBe(['subject' => '{{Omni Subject}}', 'message' => '{{Omni Message}}']);
});

test('push para sequência sem passos falha sem chamar o import', function () {
    Http::fake([SH.'/sequences*' => Http::response(sequencesResponse())]);

    expect(fn () => saleshandy()->push('SEQ2', [new OutboundProspect(email: 'a@b.com')]))->toThrow(OutboundException::class, 'has no steps');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'import-with-field-name'));
});

test('registerWebhook assina os quatro eventos e devolve o id', function () {
    Http::fake([SH.'/webhook/create' => Http::response(['payload' => ['id' => 'WH1']], 201)]);

    expect(saleshandy()->registerWebhook('https://app.test/hook'))->toBe('WH1');

    Http::assertSent(fn (Request $r) => $r['webhookUrl'] === 'https://app.test/hook'
        && $r['webhookEvent'] === ['email-sent', 'reply-received', 'email-bounced', 'prospect-unsubscribed']);
});

test('parseWebhook traduz os eventos e ignora os demais', function () {
    $event = saleshandy()->parseWebhook([
        'event' => 'reply-received',
        'replyReceiveAt' => '2026-10-15T10:00:00.000Z',
        'prospect' => ['email' => ' Ana@Clinica.com '],
        'sequence' => ['id' => 'SEQ1'],
    ]);

    expect($event->type)->toBe(OutboundEventType::Replied)
        ->and($event->email)->toBe('ana@clinica.com')
        ->and($event->occurredAt)->toBe('2026-10-15T10:00:00.000Z')
        ->and($event->campaignId)->toBe('SEQ1')
        ->and(saleshandy()->parseWebhook(['event' => 'email-opened', 'prospect' => ['email' => 'a@b.com']]))->toBeNull()
        ->and(saleshandy()->parseWebhook(['event' => 'email-sent', 'prospect' => []]))->toBeNull();
});

test('prospect só leva nome de pessoa quando ele difere da empresa', function () {
    $places = Lead::factory()->make(['name' => 'Clínica Bela', 'company_name' => 'Clínica Bela', 'email' => 'Oi@Bela.com', 'phone' => '5511999990000']);
    $person = Lead::factory()->make(['name' => 'Ana Paula Souza', 'company_name' => 'Clínica Bela', 'email' => 'ana@bela.com']);

    expect(OutboundProspect::fromLead($places)->toArray())->toMatchArray(['email' => 'oi@bela.com', 'first_name' => '', 'company' => 'Clínica Bela', 'phone' => '+5511999990000'])
        ->and(OutboundProspect::fromLead($person)->toArray())->toMatchArray(['first_name' => 'Ana', 'last_name' => 'Paula Souza']);
});

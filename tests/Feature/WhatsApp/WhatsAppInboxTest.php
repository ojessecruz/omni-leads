<?php

use App\Actions\WhatsApp\RegisterWhatsAppWebhook;
use App\Domains\WhatsApp\Data\WhatsAppMessageData;
use App\Domains\WhatsApp\PhoneNumbers;
use App\Domains\WhatsApp\WebhookParser;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Enums\WhatsAppMessageStatus;
use App\Enums\WhatsAppMessageType;
use App\Events\LeadStatusChanged;
use App\Events\WhatsAppMessageStored;
use App\Jobs\DownloadWhatsAppMediaJob;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Jobs\SendWhatsAppJob;
use App\Models\Icp;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config()->set('enrichment.evolution.requests_per_minute', 100);
});

/** Paired Evolution integration (https://evo.test, instance "main") with a registered webhook secret. */
function pairedWhatsApp(User $user): Integration
{
    return connectIntegration($user, IntegrationProvider::Evolution, [
        'base_url' => 'https://evo.test', 'key' => 'ek', 'instance' => 'main',
        'connected_at' => now()->toIso8601String(), 'webhook_secret' => 'secret',
    ]);
}

function leadWithPhone(User $user, string $phone, array $attributes = []): Lead
{
    return Lead::factory()->for(Icp::factory()->for(Project::factory()->for($user)))->create(['phone' => $phone, ...$attributes]);
}

/** @param  array<string, mixed>  $message  Baileys message content */
function upsertPayload(string $jid, array $message = ['conversation' => 'Oi, tenho interesse'], array $key = [], array $extra = []): array
{
    return [
        'event' => 'messages.upsert',
        'instance' => 'main',
        'data' => [
            'key' => ['remoteJid' => $jid, 'fromMe' => false, 'id' => 'MSG-'.md5(json_encode([$jid, $message, $key])), ...$key],
            'pushName' => 'Ana',
            'message' => $message,
            'messageTimestamp' => 1760630400,
            ...$extra,
        ],
    ];
}

function deliverWebhook(array $payload): void
{
    $parser = app(WebhookParser::class);
    $user = User::find(Integration::where('provider', IntegrationProvider::Evolution)->value('user_id'));

    (new ProcessWhatsAppWebhookJob(
        $user->id,
        array_map(fn (WhatsAppMessageData $m) => $m->toArray(), $parser->messages($payload)),
        array_map(fn ($s) => $s->toArray(), $parser->statuses($payload)),
    ))->handle(app(WhatsAppInbox::class));
}

test('variações do telefone cobrem o nono dígito e o número sem DDI', function () {
    expect(PhoneNumbers::variants('5511987654321'))->toBe(['5511987654321', '551187654321', '11987654321', '1187654321'])
        ->and(PhoneNumbers::variants('551187654321'))->toContain('5511987654321', '11987654321')
        ->and(PhoneNumbers::variants('551133334444'))->toBe(['551133334444', '1133334444'])
        ->and(PhoneNumbers::variants('14155550100'))->toBe(['14155550100'])
        ->and(PhoneNumbers::fromJid('5511987654321:12@s.whatsapp.net'))->toBe('5511987654321');
});

test('parser lê texto, mídia e LID e ignora grupos e reações', function () {
    $parser = new WebhookParser;

    $text = $parser->messages(upsertPayload('5511987654321@s.whatsapp.net', ['extendedTextMessage' => ['text' => 'Olá']]))[0];
    expect($text->type)->toBe(WhatsAppMessageType::Text)->and($text->body)->toBe('Olá')->and($text->pushName)->toBe('Ana')->and($text->sentAt)->toBe(1760630400);

    $doc = $parser->messages(upsertPayload('5511987654321@s.whatsapp.net', ['documentWithCaptionMessage' => ['message' => ['documentMessage' => ['fileName' => 'proposta.pdf', 'mimetype' => 'application/pdf', 'caption' => 'segue']]]]))[0];
    expect($doc->type)->toBe(WhatsAppMessageType::Document)->and($doc->fileName)->toBe('proposta.pdf')->and($doc->body)->toBe('segue');

    $lid = $parser->messages(upsertPayload('123456789@lid', key: ['remoteJidAlt' => '5511987654321@s.whatsapp.net']))[0];
    expect($lid->phone)->toBe('5511987654321');

    expect($parser->messages(upsertPayload('120363@g.us')))->toBe([])
        ->and($parser->messages(upsertPayload('5511987654321@s.whatsapp.net', ['reactionMessage' => ['text' => '👍']])))->toBe([])
        ->and($parser->messages(['event' => 'MESSAGES_UPSERT', 'data' => upsertPayload('5511987654321@s.whatsapp.net')['data']]))->toHaveCount(1);

    $statuses = $parser->statuses(['event' => 'messages.update', 'data' => [['keyId' => 'A', 'status' => 'READ'], ['keyId' => 'B', 'status' => 'DELIVERY_ACK']]]);
    expect(array_map(fn ($s) => [$s->externalId, $s->status], $statuses))->toBe([['A', WhatsAppMessageStatus::Read], ['B', WhatsAppMessageStatus::Delivered]]);
});

test('webhook recusa segredo errado', function () {
    $integration = pairedWhatsApp(User::factory()->create());

    $this->postJson(route('whatsapp.webhook', [$integration, 'wrong']), upsertPayload('5511987654321@s.whatsapp.net'))->assertUnauthorized();
});

test('webhook descarta conversas que não são de leads e enfileira as de leads', function () {
    Queue::fake();
    $user = User::factory()->create();
    $integration = pairedWhatsApp($user);
    leadWithPhone($user, '5511987654321');
    $url = route('whatsapp.webhook', [$integration, 'secret']);

    $this->postJson($url, upsertPayload('5521999990000@s.whatsapp.net'))->assertOk();
    $this->postJson($url, [...upsertPayload('5511987654321@s.whatsapp.net'), 'instance' => 'other'])->assertOk();
    Queue::assertNothingPushed();

    $this->postJson($url, upsertPayload('5511987654321@s.whatsapp.net'))->assertOk();
    Queue::assertPushed(ProcessWhatsAppWebhookJob::class, fn ($job) => $job->userId === $user->id && $job->messages[0]['body'] === 'Oi, tenho interesse');
});

test('resposta do lead cria uma conversa por número, ligada aos leads de todos os projetos, e marca respondeu', function () {
    Event::fake([LeadStatusChanged::class]);
    $user = User::factory()->create();
    pairedWhatsApp($user);
    $contacted = leadWithPhone($user, '5511987654321', ['status' => LeadStatus::Contacted]);
    $otherProject = leadWithPhone($user, '11987654321', ['status' => LeadStatus::Discarded]);
    leadWithPhone(User::factory()->create(), '5511987654321');

    $payload = upsertPayload('551187654321@s.whatsapp.net'); // old account: no ninth digit
    deliverWebhook($payload);
    deliverWebhook($payload); // Evolution retries

    $conversation = WhatsAppConversation::sole();
    expect($conversation->user_id)->toBe($user->id)
        ->and($conversation->name)->toBe('Ana')
        ->and($conversation->unread_count)->toBe(1)
        ->and($conversation->last_message_preview)->toBe('Oi, tenho interesse')
        ->and($conversation->leads()->pluck('leads.id')->sort()->values()->all())->toBe([$contacted->id, $otherProject->id])
        ->and($conversation->messages()->count())->toBe(1)
        ->and($contacted->fresh()->status)->toBe(LeadStatus::Replied)
        ->and($otherProject->fresh()->status)->toBe(LeadStatus::Discarded);

    Event::assertDispatchedTimes(LeadStatusChanged::class, 1);
});

test('mensagem enviada pelo celular entra na conversa sem contar como não lida', function () {
    $user = User::factory()->create();
    pairedWhatsApp($user);
    $lead = leadWithPhone($user, '5511987654321', ['status' => LeadStatus::Qualified]);

    deliverWebhook(upsertPayload('5511987654321@s.whatsapp.net', ['conversation' => 'Bom dia!'], ['fromMe' => true]));

    $message = WhatsAppMessage::sole();
    expect($message->from_me)->toBeTrue()
        ->and($message->sent_via)->toBe('phone')
        ->and($message->status)->toBe(WhatsAppMessageStatus::Sent)
        ->and($message->conversation->unread_count)->toBe(0)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

test('confirmações de entrega só avançam o status', function () {
    $user = User::factory()->create();
    pairedWhatsApp($user);
    $message = WhatsAppMessage::factory()
        ->for(WhatsAppConversation::factory()->for($user), 'conversation')
        ->create(['external_id' => 'OUT1', 'from_me' => true, 'status' => WhatsAppMessageStatus::Sent]);

    deliverWebhook(['event' => 'messages.update', 'data' => ['keyId' => 'OUT1', 'status' => 'READ']]);
    deliverWebhook(['event' => 'messages.update', 'data' => ['keyId' => 'OUT1', 'status' => 'DELIVERY_ACK']]);

    expect($message->fresh()->status)->toBe(WhatsAppMessageStatus::Read);
});

test('mídia recebida é baixada da Evolution para o disco', function () {
    Storage::fake();
    Queue::fake([DownloadWhatsAppMediaJob::class]);
    Http::fake(['https://evo.test/chat/getBase64FromMediaMessage/main' => Http::response(['base64' => base64_encode('fake-ogg'), 'mimetype' => 'audio/ogg; codecs=opus'])]);
    $user = User::factory()->create();
    pairedWhatsApp($user);
    leadWithPhone($user, '5511987654321');

    deliverWebhook(upsertPayload('5511987654321@s.whatsapp.net', ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'ptt' => true]]));

    $message = WhatsAppMessage::sole();
    expect($message->conversation->last_message_preview)->toBe('[Audio]');
    Queue::assertPushed(DownloadWhatsAppMediaJob::class, fn ($job) => $job->messageId === $message->id);

    app()->call([new DownloadWhatsAppMediaJob($message->id), 'handle']);

    $message->refresh();
    expect($message->media_path)->toBe("whatsapp/{$user->id}/{$message->whatsapp_conversation_id}/{$message->id}.ogg")
        ->and($message->media_mime)->toBe('audio/ogg');
    Storage::assertExists($message->media_path);
    Http::assertSent(fn ($req) => $req['message']['key']['id'] === $message->external_id);
});

test('envio pelo Omni entra na conversa e não volta um lead que respondeu para contatado', function () {
    Http::fake(['https://evo.test/message/sendText/main' => Http::response([
        'key' => ['remoteJid' => '5511987654321@s.whatsapp.net', 'fromMe' => true, 'id' => 'OMNI1'],
        'message' => ['conversation' => 'Que bom! Vamos marcar?'],
        'messageTimestamp' => '1760630500',
        'status' => 'PENDING',
    ])]);
    $user = User::factory()->create();
    pairedWhatsApp($user);
    $lead = leadWithPhone($user, '5511987654321', ['status' => LeadStatus::Replied]);

    app()->call([new SendWhatsAppJob($lead->id, 'Que bom! Vamos marcar?'), 'handle']);
    deliverWebhook(upsertPayload('5511987654321@s.whatsapp.net', ['conversation' => 'Que bom! Vamos marcar?'], ['fromMe' => true, 'id' => 'OMNI1']));

    $message = WhatsAppMessage::sole();
    expect($message->sent_via)->toBe('omni')
        ->and($message->status)->toBe(WhatsAppMessageStatus::Pending)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Replied);
});

test('registra o webhook na Evolution uma vez e guarda segredo e URL', function () {
    URL::forceRootUrl('https://app.omnileads.example');
    URL::forceScheme('https');
    Http::fake(['https://evo.test/webhook/set/main' => Http::response(['webhook' => ['enabled' => true]])]);
    $integration = connectIntegration(User::factory()->create(), IntegrationProvider::Evolution);
    $register = app(RegisterWhatsAppWebhook::class);

    expect($register($integration))->toBeTrue()
        ->and($register($integration->fresh()))->toBeTrue();

    $credentials = $integration->fresh()->credentials;
    expect($credentials['webhook_url'])->toBe('https://app.omnileads.example/whatsapp/'.$integration->id.'/webhook/'.$credentials['webhook_secret']);

    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => $req['webhook']['url'] === $credentials['webhook_url']
        && $req['webhook']['events'] === RegisterWhatsAppWebhook::EVENTS);
});

test('não registra webhook com URL local nem para telefone não pareado', function () {
    Http::fake();
    $paired = connectIntegration(User::factory()->create(), IntegrationProvider::Evolution);
    $unpaired = connectIntegration(User::factory()->create(), IntegrationProvider::Evolution, ['base_url' => 'https://evo.test', 'key' => 'ek']);

    expect(app(RegisterWhatsAppWebhook::class)($paired))->toBeFalse()
        ->and(app(RegisterWhatsAppWebhook::class)($unpaired))->toBeFalse();
    Http::assertNothingSent();
});

test('mensagem nova é transmitida no canal privado do dono com o total de não lidas', function () {
    Event::fake([WhatsAppMessageStored::class]);
    $user = User::factory()->create();
    pairedWhatsApp($user);
    leadWithPhone($user, '5511987654321');
    WhatsAppConversation::factory()->for($user)->create(['unread_count' => 2]);

    deliverWebhook(upsertPayload('5511987654321@s.whatsapp.net'));

    Event::assertDispatched(WhatsAppMessageStored::class, function (WhatsAppMessageStored $event) use ($user) {
        $payload = $event->broadcastWith();

        return $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id
            && $event->broadcastAs() === 'whatsapp.message'
            && $payload['unread_total'] === 3
            && $payload['name'] === 'Ana'
            && $payload['preview'] === 'Oi, tenho interesse';
    });
});

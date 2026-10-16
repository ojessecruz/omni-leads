<?php

use App\Domains\Enrichment\Clients\EvolutionClient;
use App\Domains\WhatsApp\FirstContactLimit;
use App\Domains\WhatsApp\WebhookParser;
use App\Domains\WhatsApp\WhatsAppInbox;
use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Enums\WhatsAppMessageStatus;
use App\Enums\WhatsAppMessageType;
use App\Jobs\MarkWhatsAppReadJob;
use App\Jobs\SendWhatsAppReplyJob;
use App\Livewire\Conversations\Chat;
use App\Livewire\Conversations\Index;
use App\Livewire\Leads\Show;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('enrichment.evolution.requests_per_minute', 100);
});

/** A conversation of $user with a lead in one of their projects; $replied adds a message from the lead. */
function conversationWithLead(User $user, bool $replied = true, array $leadAttributes = []): WhatsAppConversation
{
    $lead = Lead::factory()->for(Icp::factory()->for(Project::factory()->for($user)))->create(['phone' => '5511987654321', ...$leadAttributes]);
    $conversation = WhatsAppConversation::factory()->for($user)->create(['phone' => '5511987654321', 'remote_jid' => '5511987654321@s.whatsapp.net', 'name' => 'Ana']);
    $conversation->leads()->attach($lead);

    if ($replied) {
        WhatsAppMessage::factory()->for($conversation, 'conversation')->create(['external_id' => 'IN1', 'body' => 'Oi!']);
    }

    return $conversation;
}

test('caixa lista só as conversas do usuário, filtra pela busca e não abre conversa de outro', function () {
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);
    WhatsAppConversation::factory()->for($user)->create(['name' => 'Ana Spa', 'last_message_at' => now()->subHour()]);
    WhatsAppConversation::factory()->for($user)->create(['name' => 'Bruno Barbearia', 'last_message_at' => now()]);
    $foreign = WhatsAppConversation::factory()->create(['name' => 'Outro Dono']);

    Livewire::actingAs($user)->test(Index::class)
        ->assertSeeInOrder(['Bruno Barbearia', 'Ana Spa'])
        ->assertDontSee('Outro Dono')
        ->set('search', 'spa')
        ->assertSee('Ana Spa')
        ->assertDontSee('Bruno Barbearia')
        ->set('conversation', $foreign->id)
        ->assertDontSee('Outro Dono');

    expect(fn () => Livewire::actingAs($user)->test(Index::class)->call('open', $foreign->id))->toThrow(ModelNotFoundException::class);
});

test('sem WhatsApp conectado a caixa manda conectar', function () {
    Livewire::actingAs(User::factory()->create())->test(Index::class)->assertSee('Connect your WhatsApp');
});

test('abrir o chat zera as não lidas e envia as confirmações de leitura', function () {
    Queue::fake();
    $user = User::factory()->create();
    $conversation = conversationWithLead($user);
    $conversation->update(['unread_count' => 1]);

    Livewire::actingAs($user)->test(Chat::class, ['conversationId' => $conversation->id])
        ->assertSee('Oi!')
        ->assertDispatched('whatsapp-read', total: 0);

    expect($conversation->fresh()->unread_count)->toBe(0)
        ->and(WhatsAppMessage::where('external_id', 'IN1')->sole()->status)->toBe(WhatsAppMessageStatus::Read);
    Queue::assertPushed(MarkWhatsAppReadJob::class, fn ($job) => $job->messages === [['remoteJid' => '5511987654321@s.whatsapp.net', 'id' => 'IN1']]);
});

test('chat de outro usuário não abre', function () {
    $conversation = conversationWithLead(User::factory()->create());

    expect(fn () => Livewire::actingAs(User::factory()->create())->test(Chat::class, ['conversationId' => $conversation->id]))
        ->toThrow(ModelNotFoundException::class);
});

test('responder quem já escreveu enfileira o envio sem limite', function () {
    Queue::fake();
    $user = User::factory()->create();
    $conversation = conversationWithLead($user);

    Livewire::actingAs($user)->test(Chat::class, ['conversationId' => $conversation->id])
        ->set('text', 'Vamos marcar uma conversa?')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('text', '')
        ->assertSee('Vamos marcar uma conversa?');

    Queue::assertPushed(SendWhatsAppReplyJob::class, fn ($job) => $job->conversationId === $conversation->id && $job->text === 'Vamos marcar uma conversa?');
});

test('mensagens para quem nunca respondeu param no limite por hora', function () {
    Queue::fake();
    $user = User::factory()->create();
    $conversation = conversationWithLead($user, replied: false);
    $chat = Livewire::actingAs($user)->test(Chat::class, ['conversationId' => $conversation->id]);

    foreach (range(1, FirstContactLimit::PER_HOUR) as $i) {
        $chat->set('text', "Oi {$i}")->call('send')->assertHasNoErrors();
    }

    $chat->set('text', 'Mais uma')->call('send')->assertHasErrors('text');

    Queue::assertPushed(SendWhatsAppReplyJob::class, FirstContactLimit::PER_HOUR);
});

test('envio pela caixa grava a mensagem como do Omni e marca o lead como contatado', function () {
    Http::fake(['https://evo.test/message/sendText/main' => Http::response([
        'key' => ['remoteJid' => '5511987654321@s.whatsapp.net', 'fromMe' => true, 'id' => 'OUT9'],
        'message' => ['conversation' => 'Olá'],
        'status' => 'PENDING',
    ])]);
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);
    $conversation = conversationWithLead($user, replied: false, leadAttributes: ['status' => LeadStatus::Qualified]);

    (new SendWhatsAppReplyJob($conversation->id, 'Olá'))->handle(app(EvolutionClient::class), app(WebhookParser::class), app(WhatsAppInbox::class));

    $message = $conversation->messages()->sole();
    expect($message->sent_via)->toBe('omni')
        ->and($message->body)->toBe('Olá')
        ->and($conversation->leads()->first()->status)->toBe(LeadStatus::Contacted);
    Http::assertSent(fn ($req) => $req['number'] === '5511987654321');
});

test('confirmação de leitura chama a Evolution com as mensagens', function () {
    Http::fake(['https://evo.test/chat/markMessageAsRead/main' => Http::response(['ok' => true])]);
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);
    $conversation = conversationWithLead($user);

    (new MarkWhatsAppReadJob($conversation->id, [['remoteJid' => $conversation->remote_jid, 'id' => 'IN1']]))->handle(app(EvolutionClient::class));

    Http::assertSent(fn ($req) => $req['readMessages'] === [['remoteJid' => '5511987654321@s.whatsapp.net', 'fromMe' => false, 'id' => 'IN1']]);
});

test('mídia só é entregue ao dono da conversa', function () {
    Storage::fake();
    Storage::put('whatsapp/x.jpg', 'jpeg-bytes');
    $user = User::factory()->create();
    $message = WhatsAppMessage::factory()->for(conversationWithLead($user), 'conversation')
        ->create(['type' => WhatsAppMessageType::Image, 'media_path' => 'whatsapp/x.jpg', 'media_mime' => 'image/jpeg']);

    $this->actingAs($user)->get(route('conversations.media', $message))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs(User::factory()->create())->get(route('conversations.media', $message))->assertNotFound();
});

test('lead com conversa mostra o chat no lugar do botão de primeiro envio', function () {
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);
    $conversation = conversationWithLead($user);

    Livewire::actingAs($user)->test(Show::class, ['lead' => $conversation->leads()->first()])
        ->assertSee('Open in inbox')
        ->assertSeeLivewire(Chat::class)
        ->assertDontSee('Send WhatsApp');
});

test('menu mostra Conversas com o total de não lidas', function () {
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);
    WhatsAppConversation::factory()->for($user)->create(['unread_count' => 3]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSee('Conversations')
        ->assertSee('data-whatsapp-unread', false)
        ->assertSeeInOrder(['data-whatsapp-unread', '3'], false);
});

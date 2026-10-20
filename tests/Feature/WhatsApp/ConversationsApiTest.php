<?php

use App\Enums\IntegrationProvider;
use App\Jobs\MarkWhatsAppReadJob;
use App\Jobs\RefreshWhatsAppAvatarJob;
use App\Jobs\SendWhatsAppReplyJob;
use App\Livewire\Conversations\Chat;
use App\Livewire\Settings\ApiTokens;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake([RefreshWhatsAppAvatarJob::class]);
});

/** A conversation of $user with a lead; each [from_me, minutes ago] pair becomes a message. */
function apiConversation(User $user, array $messages = []): WhatsAppConversation
{
    $lead = Lead::factory()->for(Icp::factory()->for(Project::factory()->for($user)))->create();
    $conversation = WhatsAppConversation::factory()->for($user)->create(['unread_count' => 0]);
    $conversation->leads()->attach($lead);

    foreach ($messages as [$fromMe, $minutesAgo]) {
        WhatsAppMessage::factory()->for($conversation, 'conversation')->create(['from_me' => $fromMe, 'sent_at' => now()->subMinutes($minutesAgo)]);
    }

    return $conversation;
}

test('API exige token', function () {
    $this->getJson('/api/conversations/awaiting')->assertUnauthorized();
});

test('aguardando resposta lista só conversas do usuário cuja última mensagem é do contato, a mais antiga primeiro', function () {
    $user = User::factory()->create();
    $recent = apiConversation($user, [[false, 30], [true, 20], [false, 5]]);
    $old = apiConversation($user, [[true, 300], [false, 120], [false, 60]]);
    apiConversation($user, [[false, 30], [true, 10]]); // already answered
    apiConversation($user); // no messages
    apiConversation(User::factory()->create(), [[false, 500]]); // someone else's

    Sanctum::actingAs($user, ['conversations:read']);

    $this->getJson('/api/conversations/awaiting')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $old->id)
        ->assertJsonPath('data.0.waiting_since', now()->subMinutes(120)->toIso8601String())
        ->assertJsonPath('data.0.leads.0.project.id', $old->leads->first()->project_id)
        ->assertJsonPath('data.1.id', $recent->id)
        ->assertJsonPath('data.1.waiting_since', now()->subMinutes(5)->toIso8601String());
})->freezeSecond();

test('mensagens vêm da mais nova para a mais antiga, paginadas por cursor, sem marcar como lida', function () {
    $user = User::factory()->create();
    $conversation = apiConversation($user, [[false, 30], [true, 20], [false, 5]]);
    $conversation->update(['unread_count' => 2]);
    Sanctum::actingAs($user, ['conversations:read']);

    $page = $this->getJson("/api/conversations/{$conversation->id}/messages?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.from_me', false)
        ->assertJsonPath('data.1.from_me', true);

    $this->getJson("/api/conversations/{$conversation->id}/messages?per_page=2&cursor=".$page->json('meta.next_cursor'))
        ->assertJsonCount(1, 'data');

    expect($conversation->fresh()->unread_count)->toBe(2);
});

test('conversa de outro usuário dá 404', function () {
    $other = apiConversation(User::factory()->create(), [[false, 5]]);
    Sanctum::actingAs(User::factory()->create(), ['conversations:read', 'conversations:reply']);

    $this->getJson("/api/conversations/{$other->id}/messages")->assertNotFound();
    $this->postJson("/api/conversations/{$other->id}/messages", ['text' => 'Oi'])->assertNotFound();
});

test('responder enfileira o envio e marca a conversa como lida', function () {
    Queue::fake();
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);
    $conversation = apiConversation($user, [[false, 5]]);
    $conversation->update(['unread_count' => 1]);
    Sanctum::actingAs($user, ['conversations:reply']);

    $this->postJson("/api/conversations/{$conversation->id}/messages", ['text' => '  Claro, vamos conversar!  '])
        ->assertStatus(202)
        ->assertJson(['status' => 'queued']);

    Queue::assertPushed(SendWhatsAppReplyJob::class, fn ($job) => $job->conversationId === $conversation->id && $job->text === 'Claro, vamos conversar!');
    Queue::assertPushed(MarkWhatsAppReadJob::class);
    expect($conversation->fresh()->unread_count)->toBe(0);
});

test('responder valida o texto, exige WhatsApp conectado e respeita o token', function () {
    Queue::fake();
    $user = User::factory()->create();
    $conversation = apiConversation($user, [[false, 5]]);

    Sanctum::actingAs($user, ['conversations:read']);
    $this->postJson("/api/conversations/{$conversation->id}/messages", ['text' => 'Oi'])->assertForbidden();

    Sanctum::actingAs($user, ['conversations:reply']);
    $this->postJson("/api/conversations/{$conversation->id}/messages", ['text' => ''])->assertJsonValidationErrors('text');
    $this->postJson("/api/conversations/{$conversation->id}/messages", ['text' => 'Oi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['text' => 'WhatsApp is not connected']);

    Queue::assertNotPushed(SendWhatsAppReplyJob::class);
});

test('configurações de API criam token com permissões, mostram uma vez e revogam', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(ApiTokens::class)
        ->set('name', 'n8n')
        ->set('abilities', ['conversations:read'])
        ->call('create')
        ->assertHasNoErrors();

    $token = $user->tokens()->sole();
    expect($token->name)->toBe('n8n')
        ->and($token->abilities)->toBe(['conversations:read'])
        ->and($component->get('plainToken'))->toStartWith($token->id.'|');

    $component->call('revoke', $token->id)->assertSet('plainToken', null);
    expect($user->tokens()->count())->toBe(0);

    $component->set('name', 'x')->set('abilities', ['admin'])->call('create')->assertHasErrors('abilities.0');
});

test('lista todas as conversas com filtros de não lidas, bot pausado, telefone, lead e data', function () {
    $user = User::factory()->create();
    $unread = apiConversation($user, [[false, 5]]);
    $unread->update(['unread_count' => 2]);
    $paused = apiConversation($user, [[true, 60]]);
    $paused->update(['bot_paused_at' => now()]);
    $old = apiConversation($user, [[false, 600]]);
    $old->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
    apiConversation(User::factory()->create(), [[false, 1]]); // someone else's

    Sanctum::actingAs($user, ['conversations:read']);

    $this->getJson('/api/conversations')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/conversations?unread=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unread->id);
    $this->getJson('/api/conversations?bot_paused=true')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $paused->id)
        ->assertJsonPath('data.0.bot_paused', true);
    $this->getJson('/api/conversations?phone=+'.$old->phone)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $this->getJson('/api/conversations?lead_id='.$unread->leads->first()->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unread->id);
    $this->getJson('/api/conversations?updated_since='.urlencode(now()->subDay()->toIso8601String()))->assertJsonCount(2, 'data');
    $this->getJson('/api/conversations?unread=talvez')->assertJsonValidationErrors('unread');
});

test('detalhe da conversa traz os leads e conversa de outro usuário dá 404', function () {
    $user = User::factory()->create();
    $conversation = apiConversation($user, [[false, 5]]);
    $other = apiConversation(User::factory()->create(), [[false, 5]]);
    Sanctum::actingAs($user, ['conversations:read']);

    $this->getJson("/api/conversations/{$conversation->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $conversation->id)
        ->assertJsonPath('data.bot_paused', false)
        ->assertJsonPath('data.leads.0.id', $conversation->leads->first()->id);

    $this->getJson("/api/conversations/{$other->id}")->assertNotFound();
});

test('pausar e retomar o bot pela API exige permissão de resposta', function () {
    $user = User::factory()->create();
    $conversation = apiConversation($user);

    Sanctum::actingAs($user, ['conversations:read']);
    $this->patchJson("/api/conversations/{$conversation->id}", ['bot_paused' => true])->assertForbidden();

    Sanctum::actingAs($user, ['conversations:reply']);
    $this->patchJson("/api/conversations/{$conversation->id}", [])->assertJsonValidationErrors('bot_paused');
    $this->patchJson("/api/conversations/{$conversation->id}", ['bot_paused' => true])
        ->assertOk()
        ->assertJsonPath('data.bot_paused', true);
    expect($conversation->fresh()->bot_paused_at)->not->toBeNull();

    $this->patchJson("/api/conversations/{$conversation->id}", ['bot_paused' => false])->assertJsonPath('data.bot_paused', false);
    expect($conversation->fresh()->bot_paused_at)->toBeNull();
});

test('marcar como lida pela API zera as não lidas e envia os recibos', function () {
    Queue::fake();
    $user = User::factory()->create();
    $conversation = apiConversation($user, [[false, 5]]);
    $conversation->messages()->update(['status' => null]);
    $conversation->update(['unread_count' => 1]);
    Sanctum::actingAs($user, ['conversations:reply']);

    $this->postJson("/api/conversations/{$conversation->id}/read")->assertNoContent();

    expect($conversation->fresh()->unread_count)->toBe(0);
    Queue::assertPushed(MarkWhatsAppReadJob::class);
    Queue::assertNotPushed(SendWhatsAppReplyJob::class);
});

test('o chat pausa e retoma o bot e só mostra o botão para quem tem token', function () {
    $user = User::factory()->create();
    $conversation = apiConversation($user);

    Livewire::actingAs($user)->test(Chat::class, ['conversationId' => $conversation->id])
        ->assertDontSeeHtml('data-test="toggle-bot"');

    $user->createToken('bot', ['conversations:read']);

    $component = Livewire::actingAs($user)->test(Chat::class, ['conversationId' => $conversation->id])
        ->assertSeeHtml('data-test="toggle-bot"')
        ->call('toggleBot');
    expect($conversation->fresh()->bot_paused_at)->not->toBeNull();

    $component->call('toggleBot');
    expect($conversation->fresh()->bot_paused_at)->toBeNull();
});

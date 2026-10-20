<?php

use App\Enums\IntegrationProvider;
use App\Enums\LeadStatus;
use App\Events\LeadStatusChanged;
use App\Jobs\SendWhatsAppJob;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function apiLead(User $user, array $attributes = []): Lead
{
    return Lead::factory()->for(Icp::factory()->for(Project::factory()->for($user)))->create($attributes);
}

test('API de leads exige token com permissão de leitura', function () {
    $this->getJson('/api/leads')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(), ['conversations:read']);
    $this->getJson('/api/leads')->assertForbidden();
});

test('lista só leads dos projetos do usuário com filtros de projeto, ICP, status, score e data', function () {
    $user = User::factory()->create();
    $qualified = apiLead($user, ['status' => LeadStatus::Qualified, 'score' => 80]);
    $contacted = apiLead($user, ['status' => LeadStatus::Contacted, 'score' => 40]);
    $old = apiLead($user, ['status' => LeadStatus::New, 'score' => 90]);
    $old->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
    apiLead(User::factory()->create()); // someone else's

    Sanctum::actingAs($user, ['leads:read']);

    $this->getJson('/api/leads')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/leads?project_id='.$qualified->project_id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $qualified->id);
    $this->getJson('/api/leads?icp_id='.$contacted->icp_id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $contacted->id);
    $this->getJson('/api/leads?status=qualified,contacted')->assertJsonCount(2, 'data');
    $this->getJson('/api/leads?status[]=new')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
    $this->getJson('/api/leads?min_score=80')->assertJsonCount(2, 'data');
    $this->getJson('/api/leads?updated_since='.urlencode(now()->subDay()->toIso8601String()))->assertJsonCount(2, 'data');
    $this->getJson('/api/leads?status=quente')->assertJsonValidationErrors('status.0');
});

test('detalhe do lead traz projeto, ICPs, fontes e conversas; lead de outro usuário dá 404', function () {
    $user = User::factory()->create();
    $lead = apiLead($user, ['score' => 72]);
    $conversation = WhatsAppConversation::factory()->for($user)->create();
    $conversation->leads()->attach($lead);
    $other = apiLead(User::factory()->create());
    Sanctum::actingAs($user, ['leads:read']);

    $this->getJson("/api/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $lead->id)
        ->assertJsonPath('data.score', 72)
        ->assertJsonPath('data.project.id', $lead->project_id)
        ->assertJsonPath('data.icps.0.id', $lead->icp_id)
        ->assertJsonPath('data.conversation_ids', [$conversation->id])
        ->assertJsonPath('data.whatsapp_scheduled_for', null);

    $this->getJson("/api/leads/{$other->id}")->assertNotFound();
});

test('mudar o status pela API dispara o evento só quando muda e exige permissão de escrita', function () {
    Event::fake([LeadStatusChanged::class]);
    $user = User::factory()->create();
    $lead = apiLead($user, ['status' => LeadStatus::Qualified]);

    Sanctum::actingAs($user, ['leads:read']);
    $this->patchJson("/api/leads/{$lead->id}", ['status' => 'discarded'])->assertForbidden();

    Sanctum::actingAs($user, ['leads:write']);
    $this->patchJson("/api/leads/{$lead->id}", ['status' => 'quente'])->assertJsonValidationErrors('status');
    $this->patchJson("/api/leads/{$lead->id}", ['status' => 'discarded'])
        ->assertOk()
        ->assertJsonPath('data.status', 'discarded');
    $this->patchJson("/api/leads/{$lead->id}", ['status' => 'discarded'])->assertOk();

    expect($lead->fresh()->status)->toBe(LeadStatus::Discarded);
    Event::assertDispatchedTimes(LeadStatusChanged::class, 1);
    Event::assertDispatched(LeadStatusChanged::class, fn ($e) => $e->lead->is($lead) && $e->from === LeadStatus::Qualified);
});

test('primeiro WhatsApp pela API usa o modelo do projeto quando não vem texto e devolve o horário agendado', function () {
    Queue::fake();
    $user = User::factory()->create();
    connectIntegration($user, IntegrationProvider::Evolution);
    $lead = apiLead($user, ['company_name' => 'Padaria Sol']);
    $lead->project->update(['whatsapp_template' => 'Olá, {empresa}!']);
    Sanctum::actingAs($user, ['leads:write']);

    $response = $this->postJson("/api/leads/{$lead->id}/whatsapp")
        ->assertStatus(202)
        ->assertJsonPath('status', 'scheduled');

    expect($response->json('scheduled_for'))->not->toBeNull();
    Queue::assertPushed(SendWhatsAppJob::class, fn ($job) => $job->text === 'Olá, Padaria Sol!');

    // Already scheduled: a second one is refused.
    $this->postJson("/api/leads/{$lead->id}/whatsapp", ['text' => 'Oi de novo'])->assertJsonValidationErrors('text');
    Queue::assertPushed(SendWhatsAppJob::class, 1);
});

test('primeiro WhatsApp pela API recusa lead que já tem conversa ou sem WhatsApp conectado', function () {
    Queue::fake();
    $user = User::factory()->create();
    $lead = apiLead($user);
    Sanctum::actingAs($user, ['leads:write']);

    $this->postJson("/api/leads/{$lead->id}/whatsapp", ['text' => 'Oi'])
        ->assertJsonValidationErrors(['text' => 'WhatsApp is not connected']);

    connectIntegration($user, IntegrationProvider::Evolution);
    WhatsAppConversation::factory()->for($user)->create()->leads()->attach($lead);

    $this->postJson("/api/leads/{$lead->id}/whatsapp", ['text' => 'Oi'])
        ->assertJsonValidationErrors(['text' => 'already has a conversation']);

    Queue::assertNothingPushed();
});

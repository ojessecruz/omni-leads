<?php

use App\Domains\Leads\LeadScorer;
use App\Domains\Leads\LeadUpserter;
use App\Domains\LeadSources\Drivers\GooglePlaces\GooglePlacesDriver;
use App\Domains\LeadSources\LeadSourceManager;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\EnrichLeadJob;
use App\Jobs\MergeDuplicateLeadsJob;
use App\Jobs\SourceSearchJob;
use App\Livewire\Leads\Index;
use App\Livewire\Projects\Settings;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SearchJob;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('lead qualificado dispara entrega só para webhooks que escutam o evento', function () {
    Queue::fake([DeliverWebhookJob::class]);
    $project = Project::factory()->create();
    $listening = Webhook::factory()->for($project)->create(['events' => ['lead.qualified']]);
    Webhook::factory()->for($project)->create(['events' => ['search.finished']]);
    Webhook::factory()->for($project)->create(['events' => ['lead.qualified'], 'active' => false]);
    Webhook::factory()->create(['events' => ['lead.qualified']]); // outro projeto

    $icp = Icp::factory()->for($project)->create(['criteria' => ['keywords' => ['x'], 'countries' => ['BR']]]);
    $lead = Lead::factory()->for($icp)->create(['country' => 'BR', 'email' => 'a@b.com', 'whatsapp_valid' => true]);

    app(LeadScorer::class)->score($lead);

    Queue::assertPushed(DeliverWebhookJob::class, 1);
    Queue::assertPushed(DeliverWebhookJob::class, fn ($j) => $j->webhookId === $listening->id && $j->event === 'lead.qualified' && $j->data['id'] === $lead->id && $j->data['status'] === 'qualified');

    // re-scoring does not fire again
    app(LeadScorer::class)->score($lead->fresh());
    Queue::assertPushed(DeliverWebhookJob::class, 1);
});

test('marcar contatado em massa dispara lead.contacted', function () {
    Queue::fake([DeliverWebhookJob::class]);
    $project = Project::factory()->create();
    Webhook::factory()->for($project)->create(['events' => ['lead.contacted']]);
    $lead = Lead::factory()->for(Icp::factory()->for($project))->create();

    Livewire::actingAs($project->user)->test(Index::class)->set('selected', [$lead->id])->call('markAs', 'contacted');

    Queue::assertPushed(DeliverWebhookJob::class, fn ($j) => $j->event === 'lead.contacted' && $j->data['previous_status'] === 'new');
});

test('busca concluída dispara search.finished com o resumo', function () {
    Queue::fake([DeliverWebhookJob::class, EnrichLeadJob::class, MergeDuplicateLeadsJob::class]);
    config()->set('lead-sources.drivers.google_places', ['enabled' => true, 'key' => 'k', 'max_pages' => 1, 'requests_per_second' => 100]);
    Http::fake([GooglePlacesDriver::ENDPOINT => Http::response(['places' => [placePayload('p1')]])]);

    $job = SearchJob::factory()->create();
    Webhook::factory()->for($job->icp->project)->create(['events' => ['search.finished']]);

    (new SourceSearchJob($job->id))->handle(app(LeadSourceManager::class), app(LeadUpserter::class));

    Queue::assertPushed(DeliverWebhookJob::class, fn ($j) => $j->event === 'search.finished' && $j->data['results_count'] === 1 && $j->data['status'] === 'done');
    Queue::assertPushed(MergeDuplicateLeadsJob::class);
});

test('DeliverWebhookJob assina o corpo e registra sucesso', function () {
    Http::fake(['https://hooks.test/*' => Http::response('ok', 200)]);
    $hook = Webhook::factory()->create(['secret' => 'shh']);

    (new DeliverWebhookJob($hook->id, 'webhook.test', ['a' => 1], 'delivery-1'))->handle();

    Http::assertSent(function ($req) {
        $expected = 'sha256='.hash_hmac('sha256', $req->body(), 'shh');

        return $req->hasHeader('X-OmniLeads-Signature', $expected)
            && $req->hasHeader('X-OmniLeads-Event', 'webhook.test')
            && $req->hasHeader('X-OmniLeads-Delivery', 'delivery-1')
            && $req['event'] === 'webhook.test'
            && $req['data']['a'] === 1;
    });

    $hook->refresh();
    expect($hook->last_status)->toBe(200)->and($hook->last_delivered_at)->not->toBeNull()->and($hook->failures_count)->toBe(0);
});

test('DeliverWebhookJob lança em 5xx para retry sem contar falha, conta uma vez ao esgotar e desativa no limite', function () {
    Http::fake(['https://hooks.test/*' => Http::response('nope', 500)]);
    $hook = Webhook::factory()->create(['failures_count' => DeliverWebhookJob::DISABLE_AFTER_FAILURES - 1]);
    $job = new DeliverWebhookJob($hook->id, 'webhook.test', []);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    $hook->refresh();
    expect($hook->failures_count)->toBe(DeliverWebhookJob::DISABLE_AFTER_FAILURES - 1)
        ->and($hook->active)->toBeTrue()
        ->and($hook->last_status)->toBe(500);

    $job->failed(new RuntimeException('exhausted'));

    $hook->refresh();
    expect($hook->failures_count)->toBe(DeliverWebhookJob::DISABLE_AFTER_FAILURES)
        ->and($hook->active)->toBeFalse();

    // deactivated: no more deliveries
    (new DeliverWebhookJob($hook->id, 'webhook.test', []))->handle();
    Http::assertSentCount(1);
});

test('DeliverWebhookJob trata 4xx como falha definitiva: conta uma vez e não lança para retry', function () {
    Http::fake(['https://hooks.test/*' => Http::response('gone', 410)]);
    $hook = Webhook::factory()->create(['failures_count' => 3]);

    (new DeliverWebhookJob($hook->id, 'webhook.test', []))->handle();

    $hook->refresh();
    expect($hook->failures_count)->toBe(4)
        ->and($hook->last_status)->toBe(410)
        ->and($hook->active)->toBeTrue();
});

test('configurações re-autorizam em cada action, não só no mount', function () {
    $project = Project::factory()->create();
    $hook = Webhook::factory()->for($project)->create();

    $component = Livewire::actingAs($project->user)->test(Settings::class, ['project' => $project]);

    $project->update(['user_id' => User::factory()->create()->id]);

    $component->call('deleteWebhook', $hook->id)->assertForbidden();
    expect($hook->fresh())->not->toBeNull();
});

test('configurações: cria webhook com secret revelado uma vez, testa, pausa e remove', function () {
    Queue::fake([DeliverWebhookJob::class]);
    $project = Project::factory()->create();

    $component = Livewire::actingAs($project->user)->test(Settings::class, ['project' => $project])
        ->set('webhookUrl', 'não é url')
        ->call('addWebhook')->assertHasErrors('webhookUrl')
        ->set('webhookUrl', 'https://n8n.test/webhook/x')
        ->set('webhookEvents', ['lead.qualified', 'search.finished'])
        ->call('addWebhook')->assertHasNoErrors();

    $hook = $project->webhooks()->sole();

    expect($component->get('revealedSecret'))->toBe($hook->secret)
        ->and(strlen($hook->secret))->toBe(40)
        ->and($hook->events)->toBe(['lead.qualified', 'search.finished']);

    $component->call('testWebhook', $hook->id);
    Queue::assertPushed(DeliverWebhookJob::class, fn ($j) => $j->event === 'webhook.test');

    $component->call('toggleWebhook', $hook->id);
    expect($hook->fresh()->active)->toBeFalse();

    $component->call('deleteWebhook', $hook->id);
    expect($project->webhooks()->count())->toBe(0);

    // template
    $component->set('whatsappTemplate', 'Oi {primeiro_nome}, sou da Agenda.')->call('saveTemplate');
    expect($project->fresh()->whatsapp_template)->toBe('Oi {primeiro_nome}, sou da Agenda.');

    // another user cannot access
    $this->actingAs(User::factory()->create())->get(route('projects.settings', $project))->assertForbidden();
});

<?php

use App\Domains\Leads\LeadScorer;
use App\Enums\LeadStatus;
use App\Jobs\EnrichIcpLeadsJob;
use App\Jobs\EnrichLeadJob;
use App\Jobs\RescoreIcpLeadsJob;
use App\Jobs\ScoreLeadJob;
use App\Livewire\Leads\Index;
use App\Livewire\Leads\Show;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('lista leads do usuário com filtros', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $icpA = Icp::factory()->for($project)->create(['name' => 'ICP A']);
    $icpB = Icp::factory()->for($project)->create(['name' => 'ICP B']);

    $a = Lead::factory()->for($icpA)->create(['company_name' => 'Spa Alpha', 'country' => 'BR', 'score' => 80]);
    $b = Lead::factory()->for($icpB)->create(['company_name' => 'Spa Beta', 'country' => 'US', 'score' => 10]);
    $a->sources()->create(['driver' => 'google_places', 'external_id' => 'x', 'raw_payload' => []]);
    Lead::factory()->create(['company_name' => 'Spa de outro usuário']);

    Livewire::actingAs($user)->test(Index::class)
        ->assertSee('Spa Alpha')->assertSee('Spa Beta')->assertDontSee('Spa de outro usuário')
        ->set('icp', (string) $icpA->id)->assertSee('Spa Alpha')->assertDontSee('Spa Beta')
        ->set('icp', '')
        ->set('country', 'US')->assertDontSee('Spa Alpha')->assertSee('Spa Beta')
        ->set('country', '')
        ->set('minScore', 50)->assertSee('Spa Alpha')->assertDontSee('Spa Beta')
        ->set('minScore', 0)
        ->set('driver', 'google_places')->assertSee('Spa Alpha')->assertDontSee('Spa Beta')
        ->set('driver', '')
        ->set('search', 'beta')->assertDontSee('Spa Alpha')->assertSee('Spa Beta');
});

test('ações em massa alteram status só dos leads do usuário', function () {
    $user = User::factory()->create();
    $icp = Icp::factory()->for(Project::factory()->for($user))->create();
    $mine = Lead::factory()->for($icp)->create();
    $other = Lead::factory()->create();

    Livewire::actingAs($user)->test(Index::class)
        ->set('selected', [$mine->id, $other->id])
        ->call('markAs', 'discarded')
        ->assertSet('selected', []);

    expect($mine->fresh()->status)->toBe(LeadStatus::Discarded)
        ->and($other->fresh()->status)->toBe(LeadStatus::New);
});

test('ação em massa só aceita contacted ou discarded', function () {
    $lead = Lead::factory()->create();

    foreach (['qualified', 'new', 'bogus'] as $status) {
        Livewire::actingAs($lead->project->user)->test(Index::class)
            ->set('selected', [$lead->id])
            ->call('markAs', $status)
            ->assertHasErrors('status');
    }

    expect($lead->fresh()->status)->toBe(LeadStatus::New);
});

test('detalhe do lead valida o status antes de alterar', function () {
    $lead = Lead::factory()->create();

    Livewire::actingAs($lead->project->user)->test(Show::class, ['lead' => $lead])
        ->call('setStatus', 'bogus')->assertHasErrors('status')
        ->call('setStatus', 'contacted')->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::Contacted);
});

test('detalhe do lead mostra payload bruto e exige dono', function () {
    $lead = Lead::factory()->create();
    $lead->sources()->create(['driver' => 'google_places', 'external_id' => 'ChIJ123', 'raw_payload' => ['place' => ['rating' => 4.6]]]);

    $this->actingAs(User::factory()->create())->get(route('leads.show', $lead))->assertForbidden();
    $this->actingAs($lead->project->user)->get(route('leads.show', $lead))
        ->assertOk()
        ->assertSee('ChIJ123')
        ->assertSee('&quot;rating&quot;: 4.6', false);
});

test('detalhe mostra breakdown do score e enfileira enriquecimento', function () {
    Queue::fake();
    $lead = Lead::factory()->create(['email' => 'a@b.com']);

    Livewire::actingAs($lead->project->user)->test(Show::class, ['lead' => $lead])
        ->assertSee('Qualification score')
        ->assertSee('Email found')
        ->call('enrich');

    Queue::assertPushed(EnrichLeadJob::class, fn ($j) => $j->leadId === $lead->id && $j->force);
});

test('página do ICP enfileira um único job de lote para enriquecer e rescorar', function () {
    Queue::fake();
    $icp = Icp::factory()->create();
    Lead::factory()->for($icp)->create(['enriched_at' => now()]);
    Lead::factory()->for($icp)->create(['enriched_at' => null]);
    Lead::factory()->for($icp)->create(['enriched_at' => null, 'status' => LeadStatus::Discarded]);

    Livewire::actingAs($icp->project->user)->test(App\Livewire\Icps\Show::class, ['icp' => $icp])
        ->assertSee('Funnel')
        ->call('enrichAll', false)
        ->call('rescoreAll');

    Queue::assertPushed(EnrichIcpLeadsJob::class, fn ($j) => $j->icpId === $icp->id && ! $j->force);
    Queue::assertPushed(RescoreIcpLeadsJob::class, fn ($j) => $j->icpId === $icp->id);
    Queue::assertNotPushed(EnrichLeadJob::class);
    Queue::assertNotPushed(ScoreLeadJob::class);
});

test('jobs de lote percorrem os leads do ICP: enriquecer pula descartados e já enriquecidos, rescore pontua todos', function () {
    Queue::fake([EnrichLeadJob::class]);
    $icp = Icp::factory()->create(['criteria' => ['keywords' => ['spa'], 'countries' => ['BR']]]);
    $done = Lead::factory()->for($icp)->create(['enriched_at' => now(), 'score' => 0]);
    $pending = Lead::factory()->for($icp)->create(['enriched_at' => null, 'score' => 0]);
    Lead::factory()->for($icp)->create(['enriched_at' => null, 'status' => LeadStatus::Discarded]);
    Lead::factory()->create(['enriched_at' => null]); // outro ICP

    (new EnrichIcpLeadsJob($icp->id))->handle();
    Queue::assertPushed(EnrichLeadJob::class, 1);
    Queue::assertPushed(EnrichLeadJob::class, fn ($j) => $j->leadId === $pending->id);

    (new EnrichIcpLeadsJob($icp->id, force: true))->handle();
    Queue::assertPushed(EnrichLeadJob::class, 3);

    (new RescoreIcpLeadsJob($icp->id))->handle(app(LeadScorer::class));
    expect($done->fresh()->scored_at)->not->toBeNull()->and($pending->fresh()->scored_at)->not->toBeNull();
});

test('selecionar tudo marca só os leads da página atual', function () {
    $user = User::factory()->create();
    $icp = Icp::factory()->for(Project::factory()->for($user))->create();
    Lead::factory()->for($icp)->count(Index::PER_PAGE + 3)->create();

    $component = Livewire::actingAs($user)->test(Index::class)->set('selectAll', true);

    expect($component->get('selected'))->toHaveCount(Index::PER_PAGE);

    $component->set('selectAll', false)->assertSet('selected', []);
});

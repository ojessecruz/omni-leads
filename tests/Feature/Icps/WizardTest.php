<?php

use App\Enums\IcpStatus;
use App\Jobs\DispatchIcpSearchJob;
use App\Livewire\Icps\Wizard;
use App\Models\IcpDraft;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('usuário não vê wizard de projeto alheio', function () {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('icps.create', $project))
        ->assertForbidden();
});

test('actions do wizard re-autorizam: perder o projeto depois do mount bloqueia', function () {
    $project = Project::factory()->create();

    $component = Livewire::actingAs($project->user)->test(Wizard::class, ['project' => $project]);

    $project->update(['user_id' => User::factory()->create()->id]);

    $component->call('next')->assertForbidden();
});

test('fluxo manual completo: persiste rascunho, salva ICP e dispara busca', function () {
    Queue::fake();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['product_description' => null, 'problem' => null]);

    $component = Livewire::actingAs($user)->test(Wizard::class, ['project' => $project])
        ->assertSet('step', 1)
        ->call('next')
        ->assertHasErrors(['draft.product_description', 'draft.problem'])
        ->set('draft.product_description', 'Agenda online com lembretes por WhatsApp')
        ->set('draft.problem', 'Faltas e remarcações de última hora')
        ->set('draft.avg_ticket', 'R$ 297/mês')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 2);

    expect($project->fresh()->product_description)->toBe('Agenda online com lembretes por WhatsApp')
        ->and(IcpDraft::where('project_id', $project->id)->sole()->step)->toBe(2);

    $component
        ->set('mode', 'invalido')->call('next')->assertHasErrors('mode')
        ->set('mode', 'manual')->call('next')->assertSet('step', 3)
        ->call('next')->assertHasErrors(['draft.industry', 'draft.keywords'])
        ->set('draft.industry', 'estética')
        ->set('tagInput.keywords', 'clínica de estética, day spa')
        ->call('addTag', 'keywords')
        ->assertSet('draft.keywords', ['clínica de estética', 'day spa'])
        ->call('removeTag', 'keywords', 1)
        ->assertSet('draft.keywords', ['clínica de estética'])
        ->call('next')->assertSet('step', 4)
        ->set('draft.countries', [])
        ->call('next')->assertHasErrors('draft.countries')
        ->set('draft.countries', ['BR', 'US'])
        ->set('tagInput.regions', 'São Paulo')
        ->call('addTag', 'regions')
        ->call('next')->assertSet('step', 5)
        ->set('draft.company_size', '1-10')
        ->set('draft.has_website', true)
        ->set('draft.min_reviews', 10)
        ->set('draft.min_rating', 4.5)
        ->call('next')->assertSet('step', 6)
        ->assertSet('draft.name', 'estética — São Paulo');

    // Refresh: a new mount resumes from the draft.
    Livewire::actingAs($user)->test(Wizard::class, ['project' => $project])
        ->assertSet('step', 6)
        ->assertSet('draft.keywords', ['clínica de estética'])
        ->set('draft.name', 'Clínicas SP')
        ->call('save', true)
        ->assertHasNoErrors()
        ->assertRedirect();

    $icp = $project->icps()->sole();

    expect($icp->name)->toBe('Clínicas SP')
        ->and($icp->status)->toBe(IcpStatus::Active)
        ->and($icp->criteria['countries'])->toBe(['BR', 'US'])
        ->and($icp->criteria['regions'])->toBe(['São Paulo'])
        ->and($icp->criteria['company_size'])->toBe('1-10')
        ->and($icp->criteria['signals'])->toEqual(['has_website' => true, 'min_reviews' => 10, 'min_rating' => 4.5])
        ->and(IcpDraft::count())->toBe(0);

    Queue::assertPushed(DispatchIcpSearchJob::class, fn ($job) => $job->icpId === $icp->id);
});

test('salvar sem rodar deixa o ICP como rascunho e não enfileira', function () {
    Queue::fake();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    IcpDraft::create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'step' => 6,
        'mode' => 'manual',
        'draft' => ['industry' => 'estética', 'keywords' => ['spa'], 'countries' => ['BR'], 'name' => 'Spas BR'],
    ]);

    Livewire::actingAs($user)->test(Wizard::class, ['project' => $project])
        ->assertSet('step', 6)
        ->call('save', false)
        ->assertHasNoErrors();

    expect($project->icps()->sole()->status)->toBe(IcpStatus::Draft);
    Queue::assertNothingPushed();
});

test('descartar apaga o rascunho', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    Livewire::actingAs($user)->test(Wizard::class, ['project' => $project])
        ->call('next')
        ->assertHasNoErrors()
        ->call('discard')
        ->assertRedirect(route('projects.show', $project));

    expect(IcpDraft::count())->toBe(0);
});

test('fluxo Ask AI: prompt com contexto, resposta parseada vira critérios e pula o passo manual', function () {
    Queue::fake();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['product_description' => 'Agenda com lembretes', 'problem' => 'Faltas nas consultas']);

    $component = Livewire::actingAs($user)->test(Wizard::class, ['project' => $project])
        ->call('next')->assertSet('step', 2)
        ->set('mode', 'ask_ai')
        ->call('next')->assertSet('step', 3)
        ->assertSee('Copy prompt')
        ->assertSee('Agenda com lembretes')
        ->assertSee('Faltas nas consultas')
        ->call('next')->assertSet('step', 4)
        ->call('next')->assertHasErrors('draft.ai_response')
        ->set('draft.ai_response', 'texto sem json')
        ->call('next')->assertHasErrors('draft.ai_response')->assertSet('step', 4)
        ->set('draft.ai_response', "Segue:\n```json\n{\"industry\":\"estética\",\"keywords\":[\"day spa\"],\"countries\":[\"br\"],\"regions\":[\"Moema\"],\"company_size\":\"11-50\",\"job_titles\":[\"owner\"],\"signals\":{\"has_website\":true,\"min_reviews\":5,\"min_rating\":4.2}}\n```")
        ->call('next')->assertHasNoErrors()
        ->assertSet('step', 6)
        ->assertSet('draft.keywords', ['day spa'])
        ->assertSet('draft.countries', ['BR'])
        ->assertSet('draft.company_size', '11-50')
        ->assertSet('draft.name', 'estética — Moema');

    // back from 6 goes to 4 (skips 5)
    $component->call('back')->assertSet('step', 4)->call('next')->assertSet('step', 6);

    // adjusting manually switches mode and jumps to manual step 3
    $component->call('editManually')->assertSet('mode', 'manual')->assertSet('step', 3)->assertSee('Segment &amp; keywords', false);

    $component->call('next')->call('next')->call('next')->assertSet('step', 6)
        ->call('save', true)->assertHasNoErrors();

    $icp = $project->icps()->sole();
    expect($icp->criteria['regions'])->toBe(['Moema'])
        ->and($icp->criteria['signals']['min_rating'])->toBe(4.2);

    Queue::assertPushed(DispatchIcpSearchJob::class);
});

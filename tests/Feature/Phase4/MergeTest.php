<?php

use App\Domains\Leads\LeadMerger;
use App\Domains\Leads\LeadUpserter;
use App\Domains\LeadSources\Data\LeadDTO;
use App\Enums\LeadStatus;
use App\Jobs\MergeDuplicateLeadsJob;
use App\Livewire\Leads\Index;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('mesmo lead em dois ICPs do projeto vira um só, anexado aos dois', function () {
    $project = Project::factory()->create();
    $a = Icp::factory()->for($project)->create(['name' => 'A']);
    $b = Icp::factory()->for($project)->create(['name' => 'B']);
    $up = app(LeadUpserter::class);

    $first = $up->upsert($a, dto());
    $second = $up->upsert($b, dto(['externalId' => 'place-9']));

    expect($second->id)->toBe($first->id)
        ->and($first->fresh()->icp_id)->toBe($a->id)
        ->and($first->icps()->pluck('icps.id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and($a->leads()->count())->toBe(1)
        ->and($b->leads()->count())->toBe(1)
        ->and(Lead::count())->toBe(1);

    // listing filtered by B shows the lead born in A
    Livewire::actingAs($project->user)->test(Index::class)->set('icp', $b->id)->assertSee('Spa Bella');
});

test('upsert cruza chaves: lead do Places (domínio) e do CNPJ (cnpj) com o mesmo telefone viram um', function () {
    $icp = Icp::factory()->create();
    $up = app(LeadUpserter::class);

    $places = $up->upsert($icp, dto(['phone' => '5511999990000', 'cnpj' => null]));
    $cnpj = $up->upsert($icp, new LeadDTO(
        driver: 'cnpj_br', externalId: '12345678000195', name: 'Bella Ltda', companyName: 'BELLA ESTETICA LTDA',
        domain: null, email: 'fiscal@bella.com.br', phone: '+55 (11) 99999-0000', country: 'BR', city: null, cnpj: '12.345.678/0001-95', rawPayload: [],
    ));

    expect($cnpj->id)->toBe($places->id)
        ->and($cnpj->cnpj)->toBe('12345678000195')
        ->and($cnpj->domain)->toBe('spabella.com.br')
        ->and($cnpj->email)->toBe('fiscal@bella.com.br')
        ->and($cnpj->sources()->count())->toBe(2)
        ->and(Lead::count())->toBe(1);
});

test('LeadMerger mescla por e-mail descoberto depois, mantendo o mais antigo e o status mais avançado', function () {
    $project = Project::factory()->create();
    $a = Icp::factory()->for($project)->create();
    $b = Icp::factory()->for($project)->create();

    $primary = Lead::factory()->for($a)->create(['domain' => 'x.com', 'email' => null, 'phone' => null, 'city' => null, 'status' => LeadStatus::New, 'score' => 40]);
    $dup = Lead::factory()->for($b)->create(['domain' => 'y.com', 'email' => 'oi@x.com', 'phone' => '5511', 'city' => 'SP', 'status' => LeadStatus::Contacted, 'score' => 80]);
    $primary->update(['email' => 'oi@x.com']); // enrichment found the same e-mail
    $primary->sources()->create(['driver' => 'google_places', 'external_id' => 'p', 'raw_payload' => []]);
    $dup->sources()->create(['driver' => 'google_places', 'external_id' => 'p', 'raw_payload' => []]); // identical → only one remains
    $dup->sources()->create(['driver' => 'hunter', 'external_id' => 'h', 'raw_payload' => []]);
    Lead::factory()->for($a)->create(['email' => 'outro@z.com']); // not duplicated

    $merged = app(LeadMerger::class)->mergeProject($project);

    $primary->refresh();

    expect($merged)->toBe(1)
        ->and(Lead::count())->toBe(2)
        ->and(Lead::find($dup->id))->toBeNull()
        ->and($primary->domain)->toBe('x.com') // does not overwrite
        ->and($primary->phone)->toBe('5511')
        ->and($primary->city)->toBe('SP')
        ->and($primary->status)->toBe(LeadStatus::Contacted)
        ->and($primary->score)->toBe(80)
        ->and($primary->sources()->pluck('driver')->sort()->values()->all())->toBe(['google_places', 'hunter'])
        ->and($primary->icps()->count())->toBe(2);
});

test('job de merge é único por projeto e comando enfileira para todos', function () {
    Queue::fake();
    Project::factory()->count(2)->create();

    $this->artisan('leads:merge-duplicates')->assertSuccessful();

    Queue::assertPushed(MergeDuplicateLeadsJob::class, 2);
});

<?php

use App\Domains\LeadSources\LeadSourceManager;
use App\Enums\IntegrationProvider;
use App\Enums\SearchJobStatus;
use App\Jobs\DispatchIcpSearchJob;
use App\Livewire\Icps\Show;
use App\Models\Icp;
use App\Models\SearchJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('icps:rerun-due enfileira só ICPs ativos, vencidos e sem busca em andamento', function () {
    Queue::fake();

    $due = Icp::factory()->active()->create(['rerun_every_days' => 7, 'next_run_at' => now()->subHour()]);
    Icp::factory()->active()->create(['rerun_every_days' => 7, 'next_run_at' => now()->addDay()]);
    Icp::factory()->create(['rerun_every_days' => 7, 'next_run_at' => now()->subHour()]); // draft
    $busy = Icp::factory()->active()->create(['rerun_every_days' => 7, 'next_run_at' => now()->subHour()]);
    SearchJob::factory()->for($busy)->create(['status' => SearchJobStatus::Running]);

    $this->artisan('icps:rerun-due')->assertSuccessful();

    Queue::assertPushed(DispatchIcpSearchJob::class, 1);
    Queue::assertPushed(DispatchIcpSearchJob::class, fn ($j) => $j->icpId === $due->id);
});

test('DispatchIcpSearchJob agenda a próxima execução', function () {
    Queue::fake();
    $icp = Icp::factory()->create(['rerun_every_days' => 14]);
    connectIntegration($icp->project->user, IntegrationProvider::GooglePlaces);

    (new DispatchIcpSearchJob($icp->id))->handle(app(LeadSourceManager::class));

    $icp->refresh();
    expect($icp->last_run_at)->not->toBeNull()
        ->and($icp->next_run_at->diffInDays($icp->last_run_at, true))->toBeGreaterThanOrEqual(13);
});

test('página do ICP define o intervalo de re-execução', function () {
    $icp = Icp::factory()->active()->create();

    Livewire::actingAs($icp->project->user)->test(Show::class, ['icp' => $icp])
        ->call('setRerun', '30');

    expect($icp->fresh()->rerun_every_days)->toBe(30)
        ->and($icp->fresh()->next_run_at)->not->toBeNull();

    Livewire::actingAs($icp->project->user)->test(Show::class, ['icp' => $icp])->call('setRerun', '');
    expect($icp->fresh()->rerun_every_days)->toBeNull()->and($icp->fresh()->next_run_at)->toBeNull();
});

test('página do ICP rejeita intervalos fora da lista', function () {
    $icp = Icp::factory()->active()->create();

    foreach (['-1', '0', '1', 'abc'] as $bad) {
        Livewire::actingAs($icp->project->user)->test(Show::class, ['icp' => $icp])
            ->call('setRerun', $bad)
            ->assertHasErrors('days');
    }

    expect($icp->fresh()->rerun_every_days)->toBeNull();
});

test('schedule registra os comandos', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) => str_contains((string) $c, 'icps:rerun-due')))->toBeTrue()
        ->and($events->contains(fn ($c) => str_contains((string) $c, 'leads:merge-duplicates')))->toBeTrue();
});

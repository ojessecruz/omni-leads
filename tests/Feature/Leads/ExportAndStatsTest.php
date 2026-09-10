<?php

use App\Domains\Leads\IcpStats;
use App\Livewire\Leads\Index;
use App\Models\Icp;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SearchJob;
use App\Models\User;
use Livewire\Livewire;

test('exporta CSV com os filtros aplicados', function () {
    $user = User::factory()->create();
    $icp = Icp::factory()->for(Project::factory()->for($user))->create();
    $a = Lead::factory()->for($icp)->create(['company_name' => 'Spa Alpha', 'country' => 'BR', 'phone' => '5511999990000', 'whatsapp_valid' => true]);
    Lead::factory()->for($icp)->create(['company_name' => 'Spa Beta', 'country' => 'US']);
    $a->sources()->create(['driver' => 'google_places', 'external_id' => 'x', 'raw_payload' => []]);

    $response = Livewire::actingAs($user)->test(Index::class)
        ->set('country', 'BR')
        ->call('export');

    $response->assertFileDownloaded();
    $csv = base64_decode($response->effects['download']['content']);

    expect($csv)->toContain('Spa Alpha')
        ->and($csv)->toContain('+5511999990000')
        ->and($csv)->toContain('google_places')
        ->and($csv)->not->toContain('Spa Beta')
        ->and($csv)->toContain('id;company;name;website');
});

test('estatísticas: leads por fonte e custo estimado', function () {
    config()->set('lead-sources.drivers.google_places.cost_per_request', 0.035);
    config()->set('enrichment.hunter.cost_per_request', 0.1);

    $icp = Icp::factory()->create();
    SearchJob::factory()->for($icp)->create(['requests_count' => 10]);
    SearchJob::factory()->for($icp)->create(['requests_count' => 5]);

    $l1 = Lead::factory()->for($icp)->create();
    $l2 = Lead::factory()->for($icp)->create();
    $l1->sources()->create(['driver' => 'google_places', 'external_id' => 'a', 'raw_payload' => []]);
    $l1->sources()->create(['driver' => 'google_places', 'external_id' => 'b', 'raw_payload' => []]);
    $l2->sources()->create(['driver' => 'google_places', 'external_id' => 'c', 'raw_payload' => []]);
    $l1->sources()->create(['driver' => 'hunter', 'external_id' => 'h', 'raw_payload' => []]);
    $l1->sources()->create(['driver' => 'site_scrape', 'external_id' => 's', 'raw_payload' => []]);

    $stats = app(IcpStats::class);

    expect($stats->leadsBySource($icp)->all())->toBe(['google_places' => 2]);

    $cost = $stats->estimatedCost($icp);

    expect($cost['total'])->toBe(0.63) // 15 * 0.035 + 1 * 0.1
        ->and(collect($cost['items'])->firstWhere('source', 'google_places')['requests'])->toBe(15)
        ->and(collect($cost['items'])->firstWhere('source', 'hunter')['cost'])->toBe(0.1);
});

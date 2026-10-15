<?php

use App\Models\Icp;
use App\Models\Lead;

test('a migração tira a região SC e apaga os leads dos EUA do projeto', function () {
    $icp = Icp::factory()->create(['criteria' => ['keywords' => ['barbearia'], 'countries' => ['BR'], 'regions' => ['Balneário Camboriú', 'SC']]]);
    $us = Lead::factory()->for($icp->project)->for($icp)->create(['country' => 'US']);
    $br = Lead::factory()->for($icp->project)->for($icp)->create(['country' => 'BR']);
    $other = Icp::factory()->create(['criteria' => ['keywords' => ['spa'], 'countries' => ['US'], 'regions' => ['SC']]]);
    $kept = Lead::factory()->for($other->project)->for($other)->create(['country' => 'US']);

    (require database_path('migrations/2026_10_15_210000_remove_lone_sc_region_and_us_leads.php'))->up();

    expect($icp->fresh()->criteria['regions'])->toBe(['Balneário Camboriú'])
        ->and(Lead::find($us->id))->toBeNull()
        ->and(Lead::find($br->id))->not->toBeNull()
        ->and(Lead::find($kept->id))->not->toBeNull();
});

<?php

use App\Domains\Leads\LeadScorer;
use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Models\Icp;
use App\Models\Lead;

function icpWithSignals(): Icp
{
    return Icp::factory()->create(['criteria' => [
        'keywords' => ['spa'], 'countries' => ['BR'],
        'signals' => ['has_website' => true, 'min_reviews' => 10, 'min_rating' => 4.0],
    ]]);
}

test('score completo quando todos os critérios batem', function () {
    $lead = Lead::factory()->for(icpWithSignals())->create(['country' => 'BR', 'domain' => 'spa.com.br', 'email' => 'a@spa.com.br', 'whatsapp_valid' => true]);
    $lead->sources()->create(['driver' => 'google_places', 'external_id' => 'p', 'raw_payload' => ['place' => ['rating' => 4.7, 'userRatingCount' => 50]]]);

    $result = app(LeadScorer::class)->evaluate($lead);

    expect($result['score'])->toBe(100)
        ->and(collect($result['breakdown'])->pluck('met')->every(fn ($m) => $m))->toBeTrue()
        ->and($result['breakdown'])->toHaveCount(6);
});

test('score parcial usa pesos da config e normaliza sobre os aplicáveis', function () {
    config()->set('lead-scoring.weights', ['country' => 20, 'has_website' => 20, 'min_reviews' => 15, 'min_rating' => 15, 'email' => 15, 'whatsapp' => 15]);

    // ICP without signals: only country + e-mail + whatsapp apply (20+15+15 = 50)
    $icp = Icp::factory()->create(['criteria' => ['keywords' => ['spa'], 'countries' => ['BR']]]);
    $lead = Lead::factory()->for($icp)->create(['country' => 'BR', 'email' => 'a@b.com', 'whatsapp_valid' => null]);

    $result = app(LeadScorer::class)->evaluate($lead);

    expect($result['breakdown'])->toHaveCount(3)
        ->and($result['score'])->toBe(70); // (20+15)/50
});

test('sem dado do Places os sinais de avaliação contam como não atendidos', function () {
    $lead = Lead::factory()->for(icpWithSignals())->create(['country' => 'US', 'domain' => null, 'email' => null, 'whatsapp_valid' => false]);

    $result = app(LeadScorer::class)->evaluate($lead);

    expect($result['score'])->toBe(0)
        ->and(collect($result['breakdown'])->firstWhere('key', 'min_reviews')['detail'])->toBe('no data');
});

test('ScoreLeadJob qualifica acima do threshold e volta para novo abaixo; não mexe em descartado', function () {
    config()->set('lead-scoring.qualified_threshold', 60);
    $icp = Icp::factory()->create(['criteria' => ['keywords' => ['spa'], 'countries' => ['BR']]]);

    $good = Lead::factory()->for($icp)->create(['country' => 'BR', 'email' => 'a@b.com', 'whatsapp_valid' => true]);
    $weak = Lead::factory()->for($icp)->create(['country' => 'US', 'email' => null, 'status' => LeadStatus::Qualified]);
    $discarded = Lead::factory()->for($icp)->create(['country' => 'BR', 'email' => 'a@b.com', 'whatsapp_valid' => true, 'status' => LeadStatus::Discarded]);

    foreach ([$good, $weak, $discarded] as $lead) {
        (new ScoreLeadJob($lead->id))->handle(app(LeadScorer::class));
    }

    expect($good->fresh()->status)->toBe(LeadStatus::Qualified)
        ->and($good->fresh()->score)->toBe(100)
        ->and($good->fresh()->scored_at)->not->toBeNull()
        ->and($weak->fresh()->status)->toBe(LeadStatus::New)
        ->and($discarded->fresh()->status)->toBe(LeadStatus::Discarded)
        ->and($discarded->fresh()->score)->toBe(100);
});

<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/** @return array<string, mixed> payload of a Google Places (New) place */
function placePayload(string $id, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'displayName' => ['text' => "Spa {$id}"],
        'formattedAddress' => 'Rua X, São Paulo - SP, Brasil',
        'addressComponents' => [
            ['longText' => 'São Paulo', 'shortText' => 'São Paulo', 'types' => ['locality', 'political']],
            ['longText' => 'Brasil', 'shortText' => 'BR', 'types' => ['country', 'political']],
        ],
        'websiteUri' => "https://www.spa-{$id}.com.br/",
        'internationalPhoneNumber' => '+55 11 3333-4444',
        'rating' => 4.6,
        'userRatingCount' => 42,
    ], $extra);
}

<?php

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\User;
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

/**
 * Connects a provider on the user's account (BYOK), as Settings > Integrations would.
 *
 * @param  array<string, string>|null  $credentials
 */
function connectIntegration(User $user, IntegrationProvider $provider, ?array $credentials = null): Integration
{
    $integration = Integration::factory()->for($user)->provider($provider, $credentials)->create();
    $user->unsetRelation('integrations');

    return $integration;
}

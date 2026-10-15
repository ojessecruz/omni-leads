<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Integration> */
class IntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => IntegrationProvider::GooglePlaces,
            'credentials' => ['key' => 'test-'.Str::random(16)],
            'verified_at' => now(),
        ];
    }

    /** @param  array<string, string>|null  $credentials */
    public function provider(IntegrationProvider $provider, ?array $credentials = null): static
    {
        return $this->state(fn () => [
            'provider' => $provider,
            'credentials' => $credentials ?? match ($provider) {
                IntegrationProvider::Evolution => ['base_url' => 'https://evo.test', 'key' => 'ek', 'instance' => 'main', 'connected_at' => now()->toIso8601String()],
                default => ['key' => 'test-'.Str::random(16)],
            },
        ]);
    }
}

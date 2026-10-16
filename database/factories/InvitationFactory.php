<?php

namespace Database\Factories;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(48)),
            'locale' => 'en',
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ];
    }

    /** Stores the hash of a known token, so tests can open the link. */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => hash('sha256', $token)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(['accepted_at' => now()]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\OutboundConnection;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OutboundConnection> */
class OutboundConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'connector' => 'saleshandy',
            'campaign_id' => 'SEQ1',
            'campaign_name' => 'Cold outreach',
            'auto_push' => false,
            'webhook_secret' => Str::random(40),
        ];
    }

    /** The connector runs on the project owner's key, so make sure they have one. */
    public function configure(): static
    {
        return $this->afterCreating(function (OutboundConnection $connection) {
            $user = $connection->project->user;

            if (! $user->hasIntegration(IntegrationProvider::from($connection->connector))) {
                Integration::factory()->for($user)->provider(IntegrationProvider::from($connection->connector), ['key' => 'sh-key'])->create();
                $user->unsetRelation('integrations');
            }
        });
    }
}

<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Webhook> */
class WebhookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'url' => 'https://hooks.test/'.fake()->uuid(),
            'secret' => Str::random(40),
            'events' => ['lead.qualified', 'lead.contacted', 'lead.enriched', 'search.finished', 'webhook.test'],
            'active' => true,
        ];
    }
}

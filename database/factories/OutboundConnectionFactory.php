<?php

namespace Database\Factories;

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
            'credentials' => ['api_key' => 'sh-test-'.Str::random(16)],
            'campaign_id' => 'SEQ1',
            'campaign_name' => 'Cold outreach',
            'auto_push' => false,
            'webhook_secret' => Str::random(40),
        ];
    }
}

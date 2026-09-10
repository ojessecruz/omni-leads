<?php

namespace Database\Factories;

use App\Enums\SearchJobStatus;
use App\Models\Icp;
use App\Models\SearchJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SearchJob> */
class SearchJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'icp_id' => Icp::factory(),
            'driver' => 'google_places',
            'status' => SearchJobStatus::Pending,
            'params' => [
                'queries' => [['text' => 'clínica de estética in São Paulo', 'keyword' => 'clínica de estética', 'region' => 'São Paulo', 'region_code' => null]],
                'unsupported' => ['signals'],
            ],
        ];
    }
}

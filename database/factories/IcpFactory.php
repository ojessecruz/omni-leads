<?php

namespace Database\Factories;

use App\Enums\IcpStatus;
use App\Models\Icp;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Icp> */
class IcpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Clínicas de estética SP',
            'status' => IcpStatus::Draft,
            'criteria' => [
                'industry' => 'estética',
                'keywords' => ['clínica de estética', 'day spa'],
                'countries' => ['BR'],
                'regions' => ['São Paulo'],
                'company_size' => '1-10',
                'job_titles' => ['owner'],
                'signals' => ['has_website' => true, 'min_reviews' => 10, 'min_rating' => 4.0],
            ],
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => IcpStatus::Active]);
    }
}

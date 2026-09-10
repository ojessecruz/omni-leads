<?php

namespace Database\Factories;

use App\Domains\Leads\LeadUpserter;
use App\Enums\LeadStatus;
use App\Models\Icp;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        $domain = fake()->unique()->domainName();

        return [
            'icp_id' => Icp::factory(),
            'name' => fake()->company(),
            'company_name' => fake()->company(),
            'domain' => $domain,
            'email' => null,
            'phone' => '55119'.fake()->numerify('########'),
            'country' => 'BR',
            'city' => 'São Paulo',
            'score' => 0,
            'status' => LeadStatus::New,
            'dedup_hash' => LeadUpserter::hash(['domain' => $domain]),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Lead $lead) {
            $lead->project_id ??= $lead->icp->project_id;
        });
    }
}

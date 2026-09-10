<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'product_description' => fake()->sentence(12),
            'problem' => fake()->sentence(10),
            'current_customers' => fake()->sentence(8),
            'avg_ticket' => 'R$ '.fake()->numberBetween(100, 5000),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'budget_hours' => fake()->randomFloat(2, 10, 100),
            'budget_amount' => fake()->randomFloat(2, 1000, 50000),
            'hourly_rate' => fake()->randomFloat(2, 50, 200),
            'status' => Project::STATUS_ACTIVE,
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'end_date' => fake()->dateTimeBetween('now', '+3 months'),
        ];
    }
}

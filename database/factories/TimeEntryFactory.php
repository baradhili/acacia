<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-1 week', 'now');
        $end = (clone $start)->modify('+4 hours');

        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'purchase_order_id' => null,
            'start_time' => $start,
            'end_time' => $end,
            'hours' => 4.00,
            'rate' => $this->faker->randomFloat(2, 25, 150),
            'billable' => true,
            'description' => $this->faker->sentence(),
            'status' => TimeEntry::STATUS_DRAFT,
            'approved_by' => null,
            'approved_at' => null,
            'rejection_reason' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => TimeEntry::STATUS_DRAFT]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => TimeEntry::STATUS_SUBMITTED]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TimeEntry::STATUS_APPROVED,
            'approved_by' => $attributes['approved_by'] ?? User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => TimeEntry::STATUS_REJECTED,
            'rejection_reason' => $this->faker->sentence(),
        ]);
    }
}

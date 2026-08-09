<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Estimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Estimate>
 */
class EstimateFactory extends Factory
{
    protected $model = Estimate::class;

    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 100, 10000);
        $taxAmount = $subtotal * 0.10;

        return [
            'estimate_number' => null,
            'client_id' => Client::factory(),
            'status' => Estimate::STATUS_DRAFT,
            'issue_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => 0,
            'total' => $subtotal + $taxAmount,
            'notes' => $this->faker->optional()->sentence(),
            'terms' => $this->faker->optional()->sentence(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Estimate::STATUS_DRAFT]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Estimate::STATUS_SENT]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => Estimate::STATUS_ACCEPTED]);
    }
}

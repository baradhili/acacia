<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\CreditNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditNote>
 */
class CreditNoteFactory extends Factory
{
    protected $model = CreditNote::class;

    public function definition(): array
    {
        $total = $this->faker->randomFloat(2, 50, 5000);

        return [
            'credit_note_number' => null,
            'client_id' => Client::factory(),
            'status' => CreditNote::STATUS_ISSUED,
            'issue_date' => now()->toDateString(),
            'total' => $total,
            'applied_amount' => 0,
            'remaining_amount' => $total,
            'reason' => $this->faker->optional()->sentence(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => CreditNote::STATUS_DRAFT]);
    }

    public function issued(): static
    {
        return $this->state(fn (array $attributes) => ['status' => CreditNote::STATUS_ISSUED]);
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CreditNote::STATUS_APPLIED,
            'applied_at' => now(),
        ]);
    }
}

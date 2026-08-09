<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /** @var class-string<Payment> */
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'payment_number' => 'PAY-' . $this->faker->unique()->numberBetween(10000, 99999),
            'client_id' => Client::factory(),
            'received_by' => User::factory(),
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'payment_date' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'payment_method' => $this->faker->randomElement([
                Payment::METHOD_BANK_TRANSFER,
                Payment::METHOD_CREDIT_CARD,
                Payment::METHOD_CASH,
                Payment::METHOD_CHEQUE,
                Payment::METHOD_OTHER,
            ]),
            'reference' => $this->faker->optional()->uuid,
            'notes' => $this->faker->optional()->sentence(),
            'status' => Payment::STATUS_COMPLETED,
            'ifrs_receipt_id' => null,
            'credit_note_id' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_COMPLETED,
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Payment::STATUS_VOID,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Expense>
     */
    protected $model = Expense::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = $this->faker->randomFloat(2, 50, 5000);
        $taxRate = 0.10; // 10% GST
        $taxAmount = $amount * $taxRate;

        return [
            'supplier_id' => Supplier::factory(),
            'category' => $this->faker->randomElement(Expense::CATEGORIES),
            'amount' => $amount,
            'tax_amount' => $taxAmount,
            'total' => $amount + $taxAmount,
            'expense_date' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'due_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'status' => Expense::STATUS_DRAFT,
            'description' => $this->faker->sentence(),
            'reference' => 'EXP-' . $this->faker->unique()->numberBetween(1000, 9999),
            'receipt_path' => null,
            'paid_by_user_id' => null,
            'paid_date' => null,
            'payment_method' => null,
            'notes' => $this->faker->optional()->paragraph(),
        ];
    }

    /**
     * Indicate that the expense is in draft status.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_DRAFT,
        ]);
    }

    /**
     * Indicate that the expense is submitted.
     */
    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_SUBMITTED,
        ]);
    }

    /**
     * Indicate that the expense is approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_APPROVED,
        ]);
    }

    /**
     * Indicate that the expense is paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Expense::STATUS_PAID,
            'paid_date' => now(),
            'paid_by_user_id' => User::factory(),
            'payment_method' => 'bank_transfer',
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /** @var class-string<Invoice> */
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 100, 5000);
        $taxAmount = $subtotal * 0.10;
        $issueDate = $this->faker->dateTimeBetween('-1 month', 'now');

        return [
            'invoice_number' => 'INV-' . $this->faker->unique()->numberBetween(10000, 99999),
            'client_id' => Client::factory(),
            'project_id' => null,
            'purchase_order_id' => null,
            'created_by' => User::factory(),
            'status' => Invoice::STATUS_DRAFT,
            'issue_date' => $issueDate,
            'due_date' => $issueDate->modify('+30 days'),
            'paid_at' => null,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => 0,
            'total' => $subtotal + $taxAmount,
            'notes' => $this->faker->optional()->sentence(),
            'terms' => $this->faker->optional()->sentence(),
            'ifrs_invoice_id' => null,
            'is_recurring' => false,
            'recurring_frequency' => null,
            'next_recurring_date' => null,
            'parent_invoice_id' => null,
            'sent_at' => null,
            'viewed_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_DRAFT,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Invoice::STATUS_OVERDUE,
            'due_date' => now()->subDays(30),
        ]);
    }
}

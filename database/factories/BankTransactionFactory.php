<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankTransactionFactory extends Factory
{
    protected $model = BankTransaction::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement([BankTransaction::TYPE_CREDIT, BankTransaction::TYPE_DEBIT]);
        $amount = $this->faker->randomFloat(2, 10, 5000);
        
        return [
            'source' => BankTransaction::SOURCE_WISE,
            'source_id' => 'TRANSFER-' . $this->faker->unique()->randomNumber(8),
            'reference' => $this->faker->optional()->numerify('REF-####'),
            'description' => $this->faker->sentence(),
            'amount' => $type === BankTransaction::TYPE_CREDIT ? $amount : -$amount,
            'currency' => 'AUD',
            'type' => $type,
            'transaction_date' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'created_at_source' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'merchant_name' => $this->faker->optional()->company(),
            'payer_name' => $type === BankTransaction::TYPE_CREDIT ? $this->faker->name() : null,
            'payee_name' => $type === BankTransaction::TYPE_DEBIT ? $this->faker->name() : null,
            'status' => BankTransaction::STATUS_PENDING,
        ];
    }

    public function pending(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankTransaction::STATUS_PENDING,
        ]);
    }

    public function matched(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankTransaction::STATUS_MATCHED,
            'matched_transaction_id' => 1,
            'matched_transaction_type' => 'cash_receipt',
            'matched_at' => now(),
        ]);
    }

    public function ignored(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankTransaction::STATUS_IGNORED,
            'notes' => 'Manual ignore',
        ]);
    }

    public function credit(): Factory
    {
        $amount = $this->faker->randomFloat(2, 10, 5000);
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
            'type' => BankTransaction::TYPE_CREDIT,
            'payer_name' => $this->faker->name(),
        ]);
    }

    public function debit(): Factory
    {
        $amount = $this->faker->randomFloat(2, 10, 5000);
        return $this->state(fn (array $attributes) => [
            'amount' => -$amount,
            'type' => BankTransaction::TYPE_DEBIT,
            'payee_name' => $this->faker->name(),
        ]);
    }

    public function fromWise(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'source' => BankTransaction::SOURCE_WISE,
        ]);
    }

    public function manual(): Factory
    {
        return $this->state(fn (array $attributes) => [
            'source' => BankTransaction::SOURCE_MANUAL,
        ]);
    }
}

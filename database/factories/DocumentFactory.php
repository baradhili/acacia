<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $name = $this->faker->word . '.pdf';
        $path = 'uploads/' . $this->faker->date('Y/m') . '/' . Str::random(10) . '.pdf';
        
        return [
            'documentable_type' => 'App\Models\Expense',
            'documentable_id' => $this->faker->numberBetween(1, 10),
            'name' => $name,
            'file_path' => $path,
            'mime_type' => 'application/pdf',
            'size' => $this->faker->numberBetween(1024, 10485760), // 1KB to 10MB
            'uploaded_by' => User::factory(),
        ];
    }

    /**
     * Indicate the document is for an invoice.
     */
    public function forInvoice(int $invoiceId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'documentable_type' => 'App\Models\Invoice',
            'documentable_id' => $invoiceId ?? $this->faker->numberBetween(1, 10),
        ]);
    }

    /**
     * Indicate the document is for an expense.
     */
    public function forExpense(int $expenseId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'documentable_type' => 'App\Models\Expense',
            'documentable_id' => $expenseId ?? $this->faker->numberBetween(1, 10),
        ]);
    }

    /**
     * Indicate the document is for an estimate.
     */
    public function forEstimate(int $estimateId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'documentable_type' => 'App\Models\Estimate',
            'documentable_id' => $estimateId ?? $this->faker->numberBetween(1, 10),
        ]);
    }

    /**
     * Set a specific file type.
     */
    public function ofType(string $mimeType, string $extension): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->word . '.' . $extension,
            'mime_type' => $mimeType,
        ]);
    }
}

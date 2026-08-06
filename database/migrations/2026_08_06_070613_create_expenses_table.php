<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('contacts')->onDelete('cascade');
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->string('status')->default('draft'); // draft, submitted, approved, paid, cancelled
            $table->text('description')->nullable();
            $table->string('reference')->nullable(); // Supplier invoice number
            $table->string('receipt_path')->nullable(); // Path to uploaded receipt
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->date('paid_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index(['supplier_id', 'status']);
            $table->index(['expense_date', 'category']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

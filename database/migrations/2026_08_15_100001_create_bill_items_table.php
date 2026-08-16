<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->onDelete('cascade');

            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            // Per-line GST treatment: 10 = GST inclusive, 0 = GST-free.
            $table->string('tax_rate')->default('10');
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // IFRS expense account this line posts to (journals align to the
            // chart of accounts; no hard FK, matching the other ifrs_*_id
            // columns in this codebase).
            $table->unsignedBigInteger('expense_account_id')->nullable();

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('bill_id');
            $table->index('expense_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_items');
    }
};

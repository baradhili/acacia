<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wise_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('wise_id')->unique(); // Wise transaction ID
            $table->string('reference'); // Payment reference
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('type'); // DEBIT, CREDIT
            $table->date('transaction_date');
            $table->dateTime('created_at_wise'); // Original Wise timestamp
            $table->string('merchant_name')->nullable();
            $table->string('status'); // PENDING, MATCHED, IGNORED
            $table->unsignedBigInteger('matched_transaction_id')->nullable();
            $table->string('matched_transaction_type')->nullable(); // cash_receipt, purchase
            $table->dateTime('matched_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for matching queries
            $table->index(['reference', 'amount', 'transaction_date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wise_transactions');
    }
};

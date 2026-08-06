<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // wise, manual, etc.
            $table->string('source_id')->nullable(); // External ID from source
            $table->string('reference')->nullable(); // Payment reference
            $table->text('description')->nullable(); // Full description from source
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('type'); // DEBIT, CREDIT
            $table->date('transaction_date');
            $table->dateTime('created_at_source')->nullable(); // Original timestamp from source
            $table->string('merchant_name')->nullable();
            $table->string('payer_name')->nullable(); // Sender/payer name
            $table->string('payee_name')->nullable(); // Recipient/payee name
            $table->string('status'); // PENDING, MATCHED, IGNORED
            $table->unsignedBigInteger('matched_transaction_id')->nullable();
            $table->string('matched_transaction_type')->nullable(); // cash_receipt, purchase
            $table->dateTime('matched_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for matching queries
            $table->index(['source', 'source_id']);
            $table->index(['reference', 'amount', 'transaction_date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};

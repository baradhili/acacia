<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per recorded BAS settlement: the ATO payment (or refund) that
 * nets GST Payable against GST Receivable and clears both. Amounts are
 * snapshots of the netted balances at settle() time, so backdated
 * postings never rewrite a settled history. as_at is the period covered
 * ("settled up to"), settled_at the bank/journal date — they differ
 * because a BAS is lodged and paid in the month after its quarter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bas_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->date('as_at');
            $table->date('settled_at');
            $table->decimal('gst_payable', 14, 2);
            $table->decimal('gst_receivable', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('bank_amount', 14, 2);
            $table->string('direction'); // pay | refund
            $table->unsignedBigInteger('ifrs_transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reference')->nullable(); // ATO / bank statement reference
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('ifrs_entities')->cascadeOnDelete();
            $table->foreign('ifrs_transaction_id')->references('id')->on('ifrs_transactions')->nullOnDelete();
            $table->foreign('reversal_transaction_id')->references('id')->on('ifrs_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bas_settlements');
    }
};

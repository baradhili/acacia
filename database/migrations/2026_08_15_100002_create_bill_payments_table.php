<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('paid_by')->nullable()->constrained('users')->onDelete('set null');

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method'); // bank_transfer, credit_card, cash, cheque, other
            $table->string('reference')->nullable(); // Bank reference, transaction ID, etc.

            $table->text('notes')->nullable();
            $table->string('status')->default('completed');

            // Cash-basis ledger posting: supplier payments (not bills) post,
            // mirroring Payment::ifrs_receipt_id on the AR side.
            $table->unsignedBigInteger('ifrs_payment_id')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'payment_date']);
            $table->index('payment_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};

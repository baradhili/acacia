<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained('bank_transactions')->onDelete('cascade');
            $table->string('action'); // auto_match, manual_match, auto_create_receipt, auto_create_expense, ignore, unmatch, etc.
            $table->string('status'); // success, failed
            $table->unsignedBigInteger('linked_transaction_id')->nullable();
            $table->string('linked_transaction_type')->nullable();
            $table->text('details')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            // Indexes for common queries
            $table->index(['bank_transaction_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_history');
    }
};

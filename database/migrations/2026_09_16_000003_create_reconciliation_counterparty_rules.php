<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned counterparty rules: when a bank line is matched (manually or
 * automatically), the counterparty behind it — payer for money in,
 * payee/merchant for money out — is remembered with the client or
 * supplier it resolved to. Later bank lines from the same counterparty
 * that the strict matcher misses use the rule: auto-match looks for a
 * fresh unconsumed payment/bill of that counterparty, and the
 * auto-create flows resolve the client/supplier straight from the rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_counterparty_rules', function (Blueprint $table) {
            $table->id();
            $table->string('match_key', 150); // normalised payer/payee/merchant
            $table->string('direction', 6); // CREDIT | DEBIT
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->unsignedInteger('times_matched')->default(1);
            $table->dateTime('last_matched_at')->nullable();
            $table->timestamps();

            $table->unique(['match_key', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_counterparty_rules');
    }
};

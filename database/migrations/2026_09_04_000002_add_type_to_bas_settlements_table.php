<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a settlement nets: GST (the original — GST Payable vs GST
 * Receivable via the seeded Vats), PAYG withholding (2210) or income
 * tax payable (2240), the single-liability types settled with the same
 * balance-based recipe (a debit balance is an overpayment → refund).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bas_settlements', function (Blueprint $table) {
            $table->string('type')->default('gst')->after('entity_id');
            $table->index(['entity_id', 'type', 'as_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bas_settlements', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'type', 'as_at']);
            $table->dropColumn('type');
        });
    }
};

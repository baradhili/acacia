<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rounding-adjustment journal a whole-dollar settlement posts
 * beside its clearing journal: the tax accounts clear at their exact
 * ledger balances while the bank moves the rounded BAS net, and the
 * (sub-$2) difference lands in GST Rounding — the ATO takes whole
 * dollars and carries nothing over, so the cents never linger on the
 * tax accounts. Nullable: whole-dollar positions post no second
 * journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bas_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('ifrs_rounding_transaction_id')->nullable()->after('ifrs_transaction_id');
            $table->foreign('ifrs_rounding_transaction_id')
                ->references('id')->on('ifrs_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bas_settlements', function (Blueprint $table) {
            $table->dropForeign(['ifrs_rounding_transaction_id']);
            $table->dropColumn('ifrs_rounding_transaction_id');
        });
    }
};

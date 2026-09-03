<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening-balance rows a year-end close superseded when it generated
 * the next financial year's opening set. Persisted on the workflow
 * record so reopen() can restore them — the close is reversible down
 * to the opening position, not just the closing entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_year_closes', function (Blueprint $table) {
            $table->json('superseded_opening_balances')->nullable()->after('closing_transaction_ids');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_year_closes', function (Blueprint $table) {
            $table->dropColumn('superseded_opening_balances');
        });
    }
};

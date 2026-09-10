<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data retention: how many years closed financial years are kept
 * before `ledger:prune` removes their double-entry trail (after
 * writing an opening-balance snapshot at the prune boundary, so
 * as-at balances survive). Null uses the documented default (7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('retention_years')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('entity_settings', function (Blueprint $table) {
            $table->dropColumn('retention_years');
        });
    }
};

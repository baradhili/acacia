<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional business/trading name on the company profile — the name the
 * entity trades under when it differs from the legal name on
 * ifrs_entities (which stays the authoritative statutory name feeding
 * the Company Tax Return identification section).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('trading_name', 100)->nullable()->after('entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('trading_name');
        });
    }
};

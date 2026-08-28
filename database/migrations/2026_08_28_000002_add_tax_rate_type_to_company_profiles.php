<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company tax-rate classification on the company profile: 'small' (base
 * rate entity, 25%) or 'company' (other companies, 30%). Drives the
 * default franking credit rate for dividend declarations and the ATO
 * Company Tax Report estimate — the franking gross-up must use the rate
 * the company actually pays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('tax_rate_type', 10)->default('small')->after('acn'); // small | company
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('tax_rate_type');
        });
    }
};

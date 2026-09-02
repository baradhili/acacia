<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional company logo (SVG or PNG) on the company profile, stored on
 * the public disk like client/supplier logos — for letterheads, invoice
 * headers and statutory printouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('trading_name');
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }
};

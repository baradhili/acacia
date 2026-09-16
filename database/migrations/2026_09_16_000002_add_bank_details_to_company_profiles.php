<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own bank account (BSB, account number, account name) on
 * the profile — the details invoices print so clients can pay. Same
 * column shapes the shareholder registry uses for dividend payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->string('bank_bsb', 7)->nullable()->after('phone');
            $table->string('bank_account_number', 9)->nullable()->after('bank_bsb');
            $table->string('bank_account_name', 60)->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn(['bank_bsb', 'bank_account_number', 'bank_account_name']);
        });
    }
};

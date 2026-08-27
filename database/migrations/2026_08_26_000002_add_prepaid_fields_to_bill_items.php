<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid service contracts on bill lines: when is_prepaid is set the
 * payment debits the prepaid asset account (expense_account_id = 460)
 * and the amortisation engine expenses the funded amount monthly over
 * the service period (todo-list #9 — subscriptions spanning financial
 * years are prorated by posting into whichever FY each month falls).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->boolean('is_prepaid')->default(false)->after('expense_account_id');
            $table->date('service_start')->nullable()->after('is_prepaid');
            $table->date('service_end')->nullable()->after('service_start');
            $table->unsignedBigInteger('amortise_to_account_id')->nullable()->after('service_end');
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn(['is_prepaid', 'service_start', 'service_end', 'amortise_to_account_id']);
        });
    }
};

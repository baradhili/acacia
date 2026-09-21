<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-paid supplier payments: when payment_method is
 * employee_reimbursement, employee_id records who paid out of pocket and
 * is owed the reimbursement. The FK is only added when the Payroll
 * module's employees table exists — the column is harmless without it
 * (the capture path validates against employees anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('paid_by');
            if (Schema::hasTable('employees')) {
                $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            if (Schema::hasTable('employees')) {
                $table->dropForeign(['employee_id']);
            }
            $table->dropColumn('employee_id');
        });
    }
};

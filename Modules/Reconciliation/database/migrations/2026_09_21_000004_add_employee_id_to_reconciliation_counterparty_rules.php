<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learned counterparty rules can now resolve to an employee as well as a
 * client or supplier — used by the auto-matcher to pair a bank debit
 * (the reimbursement transfer to the employee) with a fresh unconsumed
 * reimbursement payment, mirroring the client/supplier arms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_counterparty_rules', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('supplier_id');
            if (Schema::hasTable('employees')) {
                $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_counterparty_rules', function (Blueprint $table) {
            if (Schema::hasTable('employees')) {
                $table->dropForeign(['employee_id']);
            }
            $table->dropColumn('employee_id');
        });
    }
};

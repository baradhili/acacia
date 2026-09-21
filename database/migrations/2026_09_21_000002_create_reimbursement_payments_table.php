<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reimbursement payments: the pay-back leg for expenses an employee paid
 * out of pocket. The capture leg is a bill_payment with payment_method
 * employee_reimbursement, which credits Employee Reimbursements Payable
 * (2280) instead of the bank; these payments clear that liability
 * (Dr 2280 / Cr Bank 320) and are what the bank feed reconciles against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursement_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique(); // REIMB-YYYY-####
            $table->unsignedBigInteger('employee_id')->index();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method')->default('bank_transfer'); // how the company paid the employee
            $table->string('reference')->nullable(); // Bank reference, transaction ID, etc.

            $table->text('notes')->nullable();
            $table->string('status')->default('completed'); // pending, completed, void

            // Ledger posting: Dr Employee Reimbursements Payable / Cr Bank.
            $table->unsignedBigInteger('ifrs_transaction_id')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'payment_date']);
        });

        // FK kept out of the create closure so the table still builds on
        // installs where the Payroll module's employees table is absent.
        if (Schema::hasTable('employees')) {
            Schema::table('reimbursement_payments', function (Blueprint $table) {
                $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_payments');
    }
};

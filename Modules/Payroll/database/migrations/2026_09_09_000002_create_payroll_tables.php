<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Australian payroll: employees (with their tax treatment — scale,
 * tax-free threshold, no-TFN — and the closely-linked / personal
 * services income markers), pay runs, and the computed payslips.
 * Processing a run posts one journal per run (see PayrollService) and
 * stores its IFRS transaction id for reversal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('ifrs_entities')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('tfn', 20)->nullable();
            $table->string('employment_type', 20)->default('employee'); // employee | director | contractor
            $table->boolean('labour_only')->default(false); // contractors: SG applies when wholly/principally for labour
            $table->string('payment_basis', 20)->default('hourly'); // hourly | salary
            $table->decimal('hourly_rate', 10, 4)->nullable();
            $table->decimal('annual_salary', 12, 2)->nullable();
            $table->boolean('tax_free_threshold')->default(true);
            $table->decimal('super_rate', 6, 4)->nullable(); // null = the SG rate
            $table->string('super_fund')->nullable();
            $table->string('super_member_id', 50)->nullable();
            // Closely linked payees (family/associates of directors) and
            // personal services workers — flagged for FBT/PSI attention.
            $table->boolean('is_closely_linked')->default(false);
            $table->boolean('is_personal_services')->default(false);
            $table->text('notes')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 10)->default('active'); // active | inactive
            $table->timestamps();

            $table->index(['entity_id', 'status']);
        });

        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('ifrs_entities')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('payment_date');
            $table->string('frequency', 12)->default('fortnightly'); // weekly | fortnightly | monthly
            $table->string('status', 12)->default('draft'); // draft | processed
            // The three journals a processed run posts: wages accrual,
            // super accrual, and the net bank payment.
            $table->unsignedBigInteger('ifrs_transaction_id')->nullable();
            $table->unsignedBigInteger('ifrs_super_transaction_id')->nullable();
            $table->unsignedBigInteger('ifrs_payment_transaction_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('hours', 8, 2)->nullable();
            $table->decimal('gross', 12, 2);
            $table->decimal('payg_withheld', 12, 2)->default(0);
            $table->decimal('super', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2);
            $table->boolean('is_closely_linked')->default(false);
            $table->boolean('is_personal_services')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['pay_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('pay_runs');
        Schema::dropIfExists('employees');
    }
};

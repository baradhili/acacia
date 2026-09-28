<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per quarterly PAYG instalment accrual: the estimate journal
 * (Dr income tax expense / Cr income tax payable) that raises the 2240
 * liability the payg_instalment BAS settlement later nets. Amounts are
 * snapshots of the instalment income and rate applied at accrue() time,
 * so backdated revenue never rewrites an accrued history — reverse and
 * re-accrue instead. period_end is the BAS quarter end the accrual
 * covers; reversed rows stay so a quarter can be accrued again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payg_instalment_accruals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->integer('fy'); // FY label (starting year), like the BAS report
            $table->tinyInteger('quarter'); // 1-4
            $table->decimal('instalment_income', 14, 2);
            $table->decimal('rate', 8, 4); // percent applied, e.g. 25.0000
            $table->decimal('amount', 14, 2);
            $table->unsignedBigInteger('ifrs_transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'period_end']);
            $table->foreign('entity_id')->references('id')->on('ifrs_entities')->cascadeOnDelete();
            $table->foreign('ifrs_transaction_id')->references('id')->on('ifrs_transactions')->nullOnDelete();
            $table->foreign('reversal_transaction_id')->references('id')->on('ifrs_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payg_instalment_accruals');
    }
};

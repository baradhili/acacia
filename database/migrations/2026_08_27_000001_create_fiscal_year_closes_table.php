<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Year-end close workflow: one row per entity per financial year,
 * progressing trial → pending_approval → approved → closed (reopened
 * when the escape hatch is used). The checklist/trial snapshots keep
 * an audit trail of exactly what was approved; closing_transaction_ids
 * records the ledger entries the close posted so a reopen can mirror
 * them back out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_year_closes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            // FY label, matching ifrs_reporting_periods.calendar_year
            // (FY 2025 = 1 Jul 2025 – 30 Jun 2026 for year_start 7).
            $table->unsignedSmallInteger('year');
            $table->string('status', 20)->default('trial');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->json('checklist')->nullable();
            $table->json('trial_totals')->nullable();
            $table->json('closing_transaction_ids')->nullable();
            $table->timestamps();

            $table->foreign('entity_id')->references('id')->on('ifrs_entities')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['entity_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_year_closes');
    }
};

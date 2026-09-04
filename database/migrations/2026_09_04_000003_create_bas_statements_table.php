<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BAS quarters frozen at lodgement. One row per lodged quarter: the
 * figures as lodged, so backdated postings can never rewrite a BAS
 * already sent to the ATO — the report prefers these over live ledger
 * recomputation. fy_end is the report's FY key (the June year-end
 * year, e.g. 2027 = Jul 2026 – Jun 2027), quarter is 1-4 from the FY
 * start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bas_statements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->unsignedSmallInteger('fy_end');
            $table->unsignedTinyInteger('quarter');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('g1', 14, 2)->default(0);
            $table->decimal('g10', 14, 2)->default(0);
            $table->decimal('g11', 14, 2)->default(0);
            $table->decimal('gst_sales', 14, 2)->default(0);
            $table->decimal('gst_purchases', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->timestamp('lodged_at')->nullable();
            $table->unsignedBigInteger('lodged_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'fy_end', 'quarter']);
            $table->foreign('entity_id')->references('id')->on('ifrs_entities')->cascadeOnDelete();
            $table->foreign('lodged_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bas_statements');
    }
};

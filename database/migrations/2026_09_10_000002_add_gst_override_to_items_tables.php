<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjustment lines: an explicit GST amount that overrides the
 * rate-derived tax (negative for downward adjustments) so subtotal
 * and GST can be adjusted separately — a negative unit price with a
 * 0% rate adjusts the ex-GST subtotal, a zero-price line with a
 * gst_override adjusts only the GST.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('gst_override', 13, 2)->nullable();
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->decimal('gst_override', 13, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('gst_override');
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('gst_override');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice line items sometimes carry more than two significant digits —
 * e.g. client reverse invoices with per-unit prices like $0.0123. Widen
 * the entry columns to four decimal places; computed money columns
 * (discount, tax, total, and the invoice-level amounts) stay at cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 4)->change();
            $table->decimal('unit_price', 12, 4)->change();
        });

        // Credit note items mirror invoice items (client reverse invoices)
        Schema::table('credit_note_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 4)->change();
            $table->decimal('unit_price', 12, 4)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->change();
            $table->decimal('unit_price', 12, 2)->change();
        });

        Schema::table('credit_note_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->change();
            $table->decimal('unit_price', 12, 2)->change();
        });
    }
};

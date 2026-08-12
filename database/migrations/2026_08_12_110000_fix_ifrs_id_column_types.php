<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ifrs_receipt_id / ifrs_invoice_id columns were originally created as
 * string but store ifrs_transactions.id (an unsigned bigint). Correct the
 * types so the relationship is accurate and a real FK is possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'ifrs_receipt_id')) {
                $table->unsignedBigInteger('ifrs_receipt_id')->nullable()->change();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'ifrs_invoice_id')) {
                $table->unsignedBigInteger('ifrs_invoice_id')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'ifrs_receipt_id')) {
                $table->string('ifrs_receipt_id')->nullable()->change();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'ifrs_invoice_id')) {
                $table->string('ifrs_invoice_id')->nullable()->change();
            }
        });
    }
};

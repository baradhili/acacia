<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some suppliers quote line items ex-GST and add the GST at the subtotal.
 * Those lines are entered with "Add GST" ticked: the entered amount
 * excludes tax and GST is added on top at the stored rate. Inclusive
 * lines (gst_added = false) keep the back-calculation treatment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->boolean('gst_added')->default(false)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn('gst_added');
        });
    }
};

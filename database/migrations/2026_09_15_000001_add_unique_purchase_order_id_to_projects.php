<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One project per purchase order at the database level: the
        // controller claims POs under a row lock, and this index is the
        // hard guarantee for any write path that bypasses it. Nullable,
        // so legacy PO-less projects are unaffected.
        Schema::table('projects', function (Blueprint $table) {
            $table->unique('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique('projects_purchase_order_id_unique');
        });
    }
};

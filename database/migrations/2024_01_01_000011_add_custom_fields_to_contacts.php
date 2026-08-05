<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add custom_fields JSON column to clients
        Schema::table('clients', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('notes');
        });

        // Add custom_fields JSON column to suppliers
        Schema::table('suppliers', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('notes');
        });

        // Add custom_fields JSON column to vendors
        Schema::table('vendors', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};

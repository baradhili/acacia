<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add type column to suppliers (default: 'supplier')
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('type', 50)->default('supplier')->after('name');
            $table->string('category', 100)->nullable()->after('abn');
        });

        // Copy vendor data to suppliers
        DB::table('suppliers')->insertUsing(
            ['name', 'type', 'email', 'phone', 'address', 'city', 'state', 'postcode', 'country', 'abn', 'category', 'notes', 'created_at', 'updated_at'],
            DB::table('vendors')->select([
                'name',
                DB::raw("'vendor' as type"),
                'email',
                'phone',
                'address',
                'city',
                'state',
                'postcode',
                'country',
                'abn',
                'category',
                'notes',
                'created_at',
                'updated_at',
            ])
        );

        // Drop vendors table
        Schema::dropIfExists('vendors');
    }

    public function down(): void
    {
        // Recreate vendors table
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('abn', 20)->nullable();
            $table->string('category', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Copy vendor data back
        DB::table('vendors')->insertUsing(
            ['name', 'email', 'phone', 'address', 'city', 'state', 'postcode', 'country', 'abn', 'category', 'notes', 'created_at', 'updated_at'],
            DB::table('suppliers')->where('type', 'vendor')->select([
                'name',
                'email',
                'phone',
                'address',
                'city',
                'state',
                'postcode',
                'country',
                'abn',
                'category',
                'notes',
                'created_at',
                'updated_at',
            ])
        );

        // Remove type and category columns from suppliers
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['type', 'category']);
        });
    }
};

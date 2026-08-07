<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Ensure supplier_id exists and is nullable
            if (Schema::hasColumn('expenses', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->change();
            }
            
            // Add project_id as optional
            if (!Schema::hasColumn('expenses', 'project_id')) {
                $table->foreignId('project_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained('projects')
                    ->onDelete('set null');
            }
        });
        
        // Add foreign key for supplier_id to point to suppliers table
        Schema::table('expenses', function (Blueprint $table) {
            // Drop existing foreign key if it exists
            $table->dropForeign(['supplier_id']);
            
            // Add new foreign key pointing to suppliers
            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'project_id')) {
                $table->dropForeign(['project_id']);
                $table->dropColumn('project_id');
            }
        });
    }
};

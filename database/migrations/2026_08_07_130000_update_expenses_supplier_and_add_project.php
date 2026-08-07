<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Handle SQLite compatibility for foreign key modifications
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            // SQLite doesn't handle FK modifications well, so use raw SQL
            Schema::table('expenses', function (Blueprint $table) use ($driver) {
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
            
            // Use raw SQL for SQLite to add FK
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
            \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS expenses_supplier_id_foreign');
            \Illuminate\Support\Facades\DB::statement('CREATE TABLE IF NOT EXISTS expenses_temp AS SELECT * FROM expenses');
            \Illuminate\Support\Facades\DB::statement('DROP TABLE expenses');
            \Illuminate\Support\Facades\DB::statement('CREATE TABLE expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                supplier_id INTEGER NULL,
                category VARCHAR NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                tax_amount DECIMAL(12,2) DEFAULT 0,
                total DECIMAL(12,2) NOT NULL,
                expense_date DATE NOT NULL,
                due_date DATE NULL,
                status VARCHAR DEFAULT "draft",
                description TEXT NULL,
                reference VARCHAR NULL,
                receipt_path VARCHAR NULL,
                paid_by_user_id INTEGER NULL,
                paid_date DATE NULL,
                payment_method VARCHAR NULL,
                notes TEXT NULL,
                project_id INTEGER NULL,
                ifrs_transaction_id INTEGER UNSIGNED NULL,
                expense_account_id INTEGER UNSIGNED NULL,
                deleted_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
                FOREIGN KEY (paid_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            )');
            \Illuminate\Support\Facades\DB::statement('INSERT INTO expenses SELECT * FROM expenses_temp');
            \Illuminate\Support\Facades\DB::statement('DROP TABLE expenses_temp');
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON');
        } else {
            // MySQL/PostgreSQL approach
            Schema::table('expenses', function (Blueprint $table) {
                if (Schema::hasColumn('expenses', 'supplier_id')) {
                    $table->unsignedBigInteger('supplier_id')->nullable()->change();
                }
                
                if (!Schema::hasColumn('expenses', 'project_id')) {
                    $table->foreignId('project_id')
                        ->nullable()
                        ->after('supplier_id')
                        ->constrained('projects')
                        ->onDelete('set null');
                }
            });
            
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropForeign(['supplier_id']);
                $table->foreign('supplier_id')
                    ->references('id')
                    ->on('suppliers')
                    ->onDelete('set null');
            });
        }
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

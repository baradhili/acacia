<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FIFO allocation has been removed in favor of manual-only allocation,
     * so the allocation_type column is no longer needed.
     */
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            if (Schema::hasColumn('payment_allocations', 'allocation_type')) {
                $table->dropColumn('allocation_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_allocations', 'allocation_type')) {
                // Restore the original column with its 'fifo' default for
                // rollback fidelity to the pre-removal schema.
                $table->string('allocation_type')->default('fifo')->after('amount');
            }
        });
    }
};

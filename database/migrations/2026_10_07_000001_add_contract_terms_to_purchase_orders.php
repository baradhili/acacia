<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase orders become the umbrella for the two commercial documents
     * clients issue: fixed-budget purchase orders (the existing kind, the
     * default for every pre-existing row) and contracts, whose budget is
     * implied from rate (hourly, inc GST) × business days × allocation% × 8h
     * and which may be amended after activation — each amendment snapshots
     * the previous and new terms so the budget history stays reconstructable.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->enum('type', ['purchase_order', 'contract'])->default('purchase_order')->after('po_number');
            $table->decimal('rate', 10, 2)->nullable()->after('budgeted_amount');
            $table->decimal('allocation', 5, 2)->nullable()->after('rate');
        });

        Schema::create('purchase_order_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('amendment_number');
            $table->decimal('previous_rate', 10, 2)->nullable();
            $table->decimal('previous_allocation', 5, 2)->nullable();
            $table->date('previous_start_date')->nullable();
            $table->date('previous_end_date')->nullable();
            $table->decimal('previous_budgeted_amount', 15, 2);
            $table->decimal('new_rate', 10, 2);
            $table->decimal('new_allocation', 5, 2);
            $table->date('new_start_date');
            $table->date('new_end_date');
            $table->decimal('new_budgeted_amount', 15, 2);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            // Explicit name: Laravel's generated one runs to 71
            // characters, over MySQL's 64-char identifier limit
            // (sqlite, the test driver, has no such limit — this bit
            // the first MySQL migrate).
            $table->unique(['purchase_order_id', 'amendment_number'], 'po_amendments_po_id_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_amendments');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['type', 'rate', 'allocation']);
        });
    }
};

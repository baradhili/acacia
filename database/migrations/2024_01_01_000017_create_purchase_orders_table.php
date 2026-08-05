<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('budgeted_amount', 15, 2);
            $table->decimal('used_amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'open', 'partially_used', 'completed', 'cancelled'])->default('draft');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('utilization_notified_80')->default(false);
            $table->boolean('utilization_notified_100')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed final schema (2026-08-16): projects (incl. purchase_order_id),
     * project_staff, purchase_orders, and time_entries with its foreign keys
     * (previously a separate alter migration). The projects ->
     * purchase_orders FK is added after purchase_orders exists because the
     * two tables reference each other.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('budget_hours', 10, 2)->nullable();
            $table->decimal('budget_amount', 15, 2)->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->enum('status', ['active', 'on_hold', 'completed', 'cancelled'])->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('project_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

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

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->onDelete('set null');
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->decimal('hours', 8, 2)->nullable();
            $table->decimal('rate', 10, 2)->nullable();
            $table->boolean('billable')->default(true);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'start_time']);
            $table->index(['project_id', 'start_time']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
        });
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('project_staff');
        Schema::dropIfExists('projects');
    }
};

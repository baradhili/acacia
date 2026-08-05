<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};

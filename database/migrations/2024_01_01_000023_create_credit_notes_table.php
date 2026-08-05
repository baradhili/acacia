<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_number')->unique();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->string('status')->default('issued'); // issued, applied, void
            $table->date('issue_date')->nullable();
            $table->date('applied_at')->nullable();
            
            $table->decimal('total', 12, 2);
            $table->decimal('applied_amount', 12, 2)->default(0);
            $table->decimal('remaining_amount', 12, 2);
            
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['client_id', 'status']);
            $table->index('credit_note_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};

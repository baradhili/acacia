<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->string('estimate_number')->unique();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->string('status')->default('draft'); // draft, sent, accepted, rejected, expired, converted
            $table->date('issue_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->date('converted_at')->nullable();
            $table->foreignId('converted_to_invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            
            $table->timestamps();
            
            $table->index(['client_id', 'status']);
            $table->index('estimate_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};

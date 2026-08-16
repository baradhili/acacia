<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number')->unique();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->string('status')->default('draft');
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('paid_at')->nullable();

            // GST lives on bill_items (per-line taxable / GST-free), not here.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->string('reference')->nullable(); // Supplier invoice number
            // Receipts/invoices from the supplier attach via the Document
            // morph (documents.documentable), like invoices.

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index('bill_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};

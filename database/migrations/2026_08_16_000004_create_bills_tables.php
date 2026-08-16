<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed final schema (2026-08-16): the accounts-payable document
     * set — bills (invoices from a supplier), their line items (per-line
     * GST treatment + IFRS expense account), supplier payments, and
     * payment allocations. Cash basis: only bill_payments post to IFRS.
     */
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

        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->onDelete('cascade');

            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            // Per-line GST treatment: 10 = GST inclusive, 0 = GST-free.
            $table->string('tax_rate')->default('10');
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // IFRS expense account this line posts to (journals align to the
            // chart of accounts; no hard FK, matching the other ifrs_*_id
            // columns in this codebase).
            $table->unsignedBigInteger('expense_account_id')->nullable();

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('bill_id');
            $table->index('expense_account_id');
        });

        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('paid_by')->nullable()->constrained('users')->onDelete('set null');

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method'); // bank_transfer, credit_card, cash, cheque, other
            $table->string('reference')->nullable(); // Bank reference, transaction ID, etc.

            $table->text('notes')->nullable();
            $table->string('status')->default('completed'); // pending, completed, void

            // Cash-basis ledger posting: supplier payments (not bills) post,
            // mirroring Payment::ifrs_receipt_id on the AR side.
            $table->unsignedBigInteger('ifrs_payment_id')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'payment_date']);
            $table->index('payment_number');
        });

        Schema::create('bill_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_payment_id')->constrained()->onDelete('cascade');
            $table->foreignId('bill_id')->constrained()->onDelete('cascade');

            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['bill_payment_id', 'bill_id']);
            $table->index('bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payment_allocations');
        Schema::dropIfExists('bill_payments');
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('bills');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed final schema (2026-08-16): the accounts-receivable document
     * set. Folds the recurring-invoice columns, payments status +
     * credit_note_id, the corrected unsignedBigInteger ifrs_*_id columns,
     * and the removal of payment_allocations.allocation_type into their
     * base creates. Table order satisfies FK dependencies
     * (credit_notes -> invoices, payments -> credit_notes,
     * estimates -> invoices).
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('purchase_order_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->string('status')->default('draft');
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('paid_at')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            $table->unsignedBigInteger('ifrs_invoice_id')->nullable();

            $table->timestamps();

            // Recurring invoice support + email tracking
            $table->boolean('is_recurring')->default(false);
            $table->string('recurring_frequency')->nullable(); // daily, weekly, monthly, yearly
            $table->date('next_recurring_date')->nullable();
            $table->foreignId('parent_invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();

            $table->index(['client_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index('invoice_number');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->foreignId('time_entry_id')->nullable()->constrained()->onDelete('set null');

            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('tax_rate')->default('10'); // GST 10%
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('invoice_id');
        });

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

        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->onDelete('cascade');

            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('tax_rate')->default('10'); // GST 10%
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->timestamps();

            $table->index('credit_note_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');

            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_method'); // bank_transfer, credit_card, cash, cheque, other
            $table->string('reference')->nullable(); // Bank reference, transaction ID, etc.

            $table->text('notes')->nullable();
            $table->string('status')->default('completed'); // pending, completed, void
            $table->unsignedBigInteger('ifrs_receipt_id')->nullable();
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->onDelete('set null');

            $table->timestamps();

            $table->index(['client_id', 'payment_date']);
            $table->index('payment_number');
            $table->index('credit_note_id');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');

            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id']);
            $table->index('invoice_id');
        });

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

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->onDelete('cascade');

            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->string('tax_rate')->default('10'); // GST 10%
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('estimate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};

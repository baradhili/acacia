<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Squashed final schema (2026-08-16): documents (polymorphic
     * attachments), bank transactions (incl. client_id), reconciliation
     * history, audit logs, fiscal periods, and widget preferences.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable'); // documentable_type, documentable_id
            $table->string('name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // wise, manual, etc.
            $table->string('source_id')->nullable(); // External ID from source
            $table->string('reference')->nullable(); // Payment reference
            $table->text('description')->nullable(); // Full description from source
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('type'); // DEBIT, CREDIT
            $table->date('transaction_date');
            $table->dateTime('created_at_source')->nullable(); // Original timestamp from source
            $table->string('merchant_name')->nullable();
            $table->string('payer_name')->nullable(); // Sender/payer name
            $table->string('payee_name')->nullable(); // Recipient/payee name
            $table->string('status'); // PENDING, MATCHED, IGNORED
            $table->unsignedBigInteger('matched_transaction_id')->nullable();
            $table->string('matched_transaction_type')->nullable(); // cash_receipt, bill, payment, invoice, ledger
            $table->dateTime('matched_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            // Added after the base table in the original history — kept last
            // to preserve column order.
            $table->foreignId('client_id')->nullable()->constrained('clients')->onDelete('set null');

            // Indexes for matching queries
            $table->index(['source', 'source_id']);
            $table->index(['reference', 'amount', 'transaction_date']);
            $table->index(['status']);
        });

        Schema::create('reconciliation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained('bank_transactions')->onDelete('cascade');
            $table->string('action'); // auto_match, manual_match, auto_create_receipt, auto_create_bill, ignore, unmatch, etc.
            $table->string('status'); // success, failed
            $table->unsignedBigInteger('linked_transaction_id')->nullable();
            $table->string('linked_transaction_type')->nullable();
            $table->text('details')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            // Indexes for common queries
            $table->index(['bank_transaction_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type'); // Model class
            $table->unsignedBigInteger('auditable_id'); // Model ID
            $table->string('action'); // created, updated, deleted
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('user_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->timestamp('created_at');

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('year');
            $table->string('period_type'); // monthly, quarterly, annual
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_locked')->default(false);
            $table->foreignId('locked_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('locked_at')->nullable();
            $table->text('lock_reason')->nullable();
            $table->timestamps();

            $table->unique(['year', 'period_type', 'start_date']);
            $table->index(['start_date', 'end_date']);
            $table->index(['is_locked']);
        });

        Schema::create('widget_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('widget_name');
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->integer('width')->default(1);
            $table->integer('height')->default(1);
            $table->boolean('visible')->default(true);
            $table->boolean('collapsed')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'widget_name']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_preferences');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('reconciliation_history');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('documents');
    }
};

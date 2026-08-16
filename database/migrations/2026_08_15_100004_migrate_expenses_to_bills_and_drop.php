<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert legacy Expenses into the Bills (accounts-payable) subsystem and
 * drop the old table.
 *
 * Each expense becomes one bill with a single line item (category mapped to
 * an IFRS expense account). Paid expenses additionally get a bill payment +
 * full allocation, carrying the old ifrs_transaction_id across so nothing
 * double-posts to the ledger. References to expenses from bank transactions,
 * reconciliation history and documents are repointed at the new bills.
 */
return new class extends Migration
{
    /** Legacy category → seeded IFRS expense account code. */
    private const CATEGORY_ACCOUNT_CODES = [
        'travel' => 5300,               // Travel & Accommodation
        'software' => 7500,             // Subscriptions & Licenses
        'subcontractors' => 5110,       // Contract Labour
        'office_supplies' => 7400,      // Office Supplies
        'equipment' => 8900,            // Other Expenses (no seeded expense account)
        'marketing' => 7700,            // Marketing & Advertising
        'utilities' => 7200,            // Utilities
        'rent' => 7100,                 // Rent & Lease
        'insurance' => 7300,            // Insurance
        'professional_services' => 7600,// Professional Fees
        'training' => 5200,             // Staff Training
        'meals' => 5500,                // Meals & Entertainment
        'communication' => 7250,        // Phone & Internet
        'other' => 8900,                // Other Expenses
    ];

    public function up(): void
    {
        if (!Schema::hasTable('expenses')) {
            return;
        }

        // Expense account ids keyed by seeded account code, for resolving
        // bill_items.expense_account_id during conversion.
        $accountIds = DB::table('ifrs_accounts')
            ->whereIn('code', array_values(self::CATEGORY_ACCOUNT_CODES))
            ->get()
            ->groupBy('code')
            ->map(fn ($rows) => $rows->first()->id);

        $expenseIdToBillId = [];
        $billSeq = [];
        $paySeq = [];

        $expenses = DB::table('expenses')->whereNull('deleted_at')->orderBy('id')->get();

        foreach ($expenses as $expense) {
            $year = substr((string) $expense->created_at, 0, 4) ?: date('Y');
            $billSeq[$year] = ($billSeq[$year] ?? 0) + 1;

            $amount = (float) $expense->amount;
            $taxAmount = (float) $expense->tax_amount;
            $total = round($amount + $taxAmount, 2);
            $isPaid = $expense->status === 'paid';

            // Map legacy approval-ish statuses onto the invoice-style
            // lifecycle: submitted/approved mean "confirmed, awaiting
            // payment" = open.
            $status = match ($expense->status) {
                'draft' => 'draft',
                'cancelled' => 'cancelled',
                'paid' => 'paid',
                default => 'open',
            };

            $now = now();
            $billId = DB::table('bills')->insertGetId([
                'bill_number' => sprintf('BILL-%s-%04d', $year, $billSeq[$year]),
                'supplier_id' => $expense->supplier_id,
                'project_id' => $expense->project_id,
                'created_by' => $expense->paid_by_user_id,
                'status' => $status,
                'bill_date' => $expense->expense_date,
                'due_date' => $expense->due_date ?? $expense->expense_date,
                'paid_at' => $isPaid ? ($expense->paid_date ?? $expense->expense_date) : null,
                'subtotal' => $amount,
                'tax_amount' => $taxAmount,
                'discount_amount' => 0,
                'total' => $total,
                'notes' => $expense->notes,
                'reference' => $expense->reference,
                'created_at' => $expense->created_at ?? $now,
                'updated_at' => $expense->updated_at ?? $now,
            ]);

            // Legacy receipt files become Documents attached to the bill,
            // matching how invoices handle attached files.
            if ($expense->receipt_path) {
                $extension = strtolower(pathinfo($expense->receipt_path, PATHINFO_EXTENSION));
                $mimeTypes = [
                    'pdf' => 'application/pdf',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                ];

                DB::table('documents')->insert([
                    'documentable_type' => 'App\\Models\\Bill',
                    'documentable_id' => $billId,
                    'name' => 'Receipt (' . basename((string) $expense->receipt_path) . ')',
                    'file_path' => $expense->receipt_path,
                    'mime_type' => $mimeTypes[$extension] ?? null,
                    'size' => null,
                    'uploaded_by' => $expense->paid_by_user_id,
                    'created_at' => $expense->created_at ?? $now,
                    'updated_at' => $expense->updated_at ?? $now,
                ]);
            }

            $accountId = $accountIds[self::CATEGORY_ACCOUNT_CODES[$expense->category] ?? 8900] ?? null;

            DB::table('bill_items')->insert([
                'bill_id' => $billId,
                'description' => $expense->description
                    ?: ucfirst(str_replace('_', ' ', (string) $expense->category)),
                'quantity' => 1,
                'unit_price' => $amount,
                'tax_rate' => $taxAmount > 0 ? '10' : '0',
                'tax_amount' => $taxAmount,
                'discount_percent' => 0,
                'discount_amount' => 0,
                'total' => $total,
                'expense_account_id' => $expense->expense_account_id ?: $accountId,
                'sort_order' => 0,
                'created_at' => $expense->created_at ?? $now,
                'updated_at' => $expense->updated_at ?? $now,
            ]);

            if ($isPaid) {
                $paySeq[$year] = ($paySeq[$year] ?? 0) + 1;
                $paymentId = DB::table('bill_payments')->insertGetId([
                    'payment_number' => sprintf('SPAY-%s-%04d', $year, $paySeq[$year]),
                    'supplier_id' => $expense->supplier_id,
                    'paid_by' => $expense->paid_by_user_id,
                    'amount' => $total,
                    'payment_date' => $expense->paid_date ?? $expense->expense_date,
                    'payment_method' => $expense->payment_method ?: 'other',
                    'reference' => $expense->reference,
                    'notes' => null,
                    'status' => 'completed',
                    // Carrying the old journal id prevents double-posting.
                    'ifrs_payment_id' => $expense->ifrs_transaction_id,
                    'created_at' => $expense->paid_date ?? $expense->updated_at ?? $now,
                    'updated_at' => $expense->updated_at ?? $now,
                ]);

                DB::table('bill_payment_allocations')->insert([
                    'bill_payment_id' => $paymentId,
                    'bill_id' => $billId,
                    'amount' => $total,
                    'notes' => null,
                    'created_at' => $expense->updated_at ?? $now,
                    'updated_at' => $expense->updated_at ?? $now,
                ]);
            }

            $expenseIdToBillId[$expense->id] = $billId;
        }

        $this->repointReferences($expenseIdToBillId);

        Schema::dropIfExists('expenses');
    }

    /**
     * Repoint bank transactions, reconciliation history rows and documents
     * that referenced App\Models\Expense at the converted bills.
     */
    private function repointReferences(array $expenseIdToBillId): void
    {
        if (Schema::hasTable('bank_transactions')) {
            DB::table('bank_transactions')
                ->where('matched_transaction_type', 'expense')
                ->update([
                    'matched_transaction_type' => 'bill',
                    'matched_transaction_id' => DB::raw(
                        'CASE matched_transaction_id'
                        . ' WHEN 0 THEN NULL ELSE matched_transaction_id END'
                    ),
                ]);

            // Map ids via PHP (portable across MySQL/SQLite).
            DB::table('bank_transactions')
                ->where('matched_transaction_type', 'bill')
                ->whereNotNull('matched_transaction_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($expenseIdToBillId) {
                    foreach ($rows as $row) {
                        if (isset($expenseIdToBillId[$row->matched_transaction_id])) {
                            DB::table('bank_transactions')
                                ->where('id', $row->id)
                                ->update(['matched_transaction_id' => $expenseIdToBillId[$row->matched_transaction_id]]);
                        }
                    }
                });
        }

        if (Schema::hasTable('reconciliation_history')) {
            DB::table('reconciliation_history')
                ->where('linked_transaction_type', 'expense')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($expenseIdToBillId) {
                    foreach ($rows as $row) {
                        $update = ['linked_transaction_type' => 'bill'];
                        if ($row->linked_transaction_id && isset($expenseIdToBillId[$row->linked_transaction_id])) {
                            $update['linked_transaction_id'] = $expenseIdToBillId[$row->linked_transaction_id];
                        }
                        DB::table('reconciliation_history')->where('id', $row->id)->update($update);
                    }
                });
        }

        if (Schema::hasTable('documents')) {
            DB::table('documents')
                ->where('documentable_type', 'App\\Models\\Expense')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($expenseIdToBillId) {
                    foreach ($rows as $row) {
                        $update = ['documentable_type' => 'App\\Models\\Bill'];
                        if (isset($expenseIdToBillId[$row->documentable_id])) {
                            $update['documentable_id'] = $expenseIdToBillId[$row->documentable_id];
                        }
                        DB::table('documents')->where('id', $row->id)->update($update);
                    }
                });
        }
    }

    public function down(): void
    {
        // The expenses table was converted and dropped; bills created from it
        // are the system of record now. Recreating an empty legacy table (the
        // original schema) is the best-effort rollback.
        Schema::create('expenses', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->string('status')->default('draft');
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->string('receipt_path')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->date('paid_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('ifrs_transaction_id')->nullable();
            $table->unsignedBigInteger('expense_account_id')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['expense_date', 'category']);
            $table->index('status');
        });
    }
};

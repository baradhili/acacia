<?php

namespace App\Models;

use IFRS\Models\Account;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    // Default expense categories
    const CATEGORIES = [
        'travel',
        'software',
        'subcontractors',
        'office_supplies',
        'equipment',
        'marketing',
        'utilities',
        'rent',
        'insurance',
        'professional_services',
        'training',
        'meals',
        'communication',
        'other',
    ];

    protected $fillable = [
        'supplier_id',
        'project_id',
        'category',
        'amount',
        'tax_amount',
        'total',
        'expense_date',
        'due_date',
        'status',
        'description',
        'reference',
        'receipt_path',
        'paid_by_user_id',
        'paid_date',
        'payment_method',
        'notes',
        'ifrs_transaction_id',
        'expense_account_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'expense_date' => 'date',
        'due_date' => 'date',
        'paid_date' => 'date',
    ];

    /**
     * Get the supplier for this expense
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * Get the project for this expense (optional)
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the user who paid this expense
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * Get the documents attached to this expense
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Calculate total from amount and tax
     */
    public function calculateTotal(): float
    {
        return (float) $this->amount + (float) $this->tax_amount;
    }

    /**
     * Check if expense can be edited
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED]);
    }

    /**
     * Check if expense can be deleted
     */
    public function canBeDeleted(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT]);
    }

    /**
     * Check if expense can be paid
     */
    public function canBePaid(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED]);
    }

    /**
     * Submit expense for approval
     */
    public function submit(): bool
    {
        if ($this->status !== self::STATUS_DRAFT) {
            return false;
        }
        
        $this->update(['status' => self::STATUS_SUBMITTED]);
        return true;
    }

    /**
     * Approve expense
     */
    public function approve(): bool
    {
        if ($this->status !== self::STATUS_SUBMITTED) {
            return false;
        }
        
        $this->update(['status' => self::STATUS_APPROVED]);
        return true;
    }

    /**
     * Mark expense as paid and create IFRS journal entry
     * Dr Expense / Cr Cash (on payment date for cash basis)
     */
    public function markAsPaid(string $paymentMethod = null, int $userId = null, string $paidDate = null): bool
    {
        if (!$this->canBePaid()) {
            return false;
        }
        
        $paidDate = $paidDate ? \Carbon\Carbon::parse($paidDate) : now();
        $ifrsTransactionId = null;

                    // Create IFRS journal entry on payment date (cash basis)
        try {
            $refPart = $this->reference ?? $this->id;
            $journalEntry = new JournalEntry([
                'date' => $paidDate,
                'narration' => "Expense payment: {$this->category} - {$refPart}",
                'reference' => $this->reference,
            ]);

            // Get expense account based on category or use default
            $expenseAccount = $this->getExpenseAccount();

            // Debit expense account (increase expense)
            $journalEntry->addLineItem(
                LineItem::create([
                    'account_id' => $expenseAccount->id,
                    'amount' => $this->total,
                    'type' => LineItem::DEBIT,
                    'tax_rate' => 0,
                ])
            );

            // Get bank/cash account based on payment method
            $bankAccount = $this->getBankAccount($paymentMethod);

            // Credit bank (decrease asset)
            $journalEntry->addLineItem(
                LineItem::create([
                    'account_id' => $bankAccount->id,
                    'amount' => $this->total,
                    'type' => LineItem::CREDIT,
                    'tax_rate' => 0,
                ])
            );

            $journalEntry->save();
            $ifrsTransactionId = $journalEntry->id;

            Log::info("Expense IFRS Journal Entry created", [
                'expense_id' => $this->id,
                'journal_entry_id' => $ifrsTransactionId,
                'amount' => $this->total,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to create IFRS journal entry for expense", [
                'expense_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            // Continue even if IFRS entry fails - don't block the payment
        }

        $this->update([
            'status' => self::STATUS_PAID,
            'paid_date' => $paidDate,
            'payment_method' => $paymentMethod,
            'paid_by_user_id' => $userId,
            'ifrs_transaction_id' => $ifrsTransactionId,
        ]);

        return true;
    }

    /**
     * Get the expense account for the category
     */
    protected function getExpenseAccount(): Account
    {
        if ($this->expense_account_id) {
            return Account::find($this->expense_account_id);
        }

        // Map category to account name pattern
        $categoryAccountMap = [
            'travel' => 'Travel Expenses',
            'software' => 'Software & Subscriptions',
            'subcontractors' => 'Subcontractor Costs',
            'office_supplies' => 'Office Supplies',
            'equipment' => 'Equipment',
            'marketing' => 'Marketing Expenses',
            'utilities' => 'Utilities',
            'rent' => 'Rent',
            'insurance' => 'Insurance',
            'professional_services' => 'Professional Fees',
            'training' => 'Training & Development',
            'meals' => 'Meals & Entertainment',
            'communication' => 'Communication',
            'other' => 'General Expenses',
        ];

        $accountName = $categoryAccountMap[$this->category] ?? 'General Expenses';

        // Find the expense account by name
        // IFRS uses OPERATING_EXPENSE, DIRECT_EXPENSE, OVERHEAD_EXPENSE, OTHER_EXPENSE
        $expenseTypes = [
            Account::OPERATING_EXPENSE,
            Account::DIRECT_EXPENSE,
            Account::OVERHEAD_EXPENSE,
            Account::OTHER_EXPENSE,
        ];

        $account = Account::where('name', $accountName)
            ->whereIn('account_type', $expenseTypes)
            ->first();

        if (!$account) {
            // Try to find any expense account as fallback
            $account = Account::whereIn('account_type', $expenseTypes)->first();
        }

        if (!$account) {
            throw new \Exception("No expense account found in IFRS chart of accounts");
        }

        return $account;
    }

    /**
     * Get the bank/cash account for the payment method
     */
    protected function getBankAccount(string $paymentMethod = null): Account
    {
        $methodAccountMap = [
            'bank_transfer' => 'Bank Account',
            'credit_card' => 'Credit Card',
            'cash' => 'Cash',
            'cheque' => 'Bank Account',
            'other' => 'Bank Account',
        ];

        $accountName = $methodAccountMap[$paymentMethod] ?? 'Bank Account';

        // Find the bank account - IFRS uses BANK, CURRENT_ASSET, etc.
        $assetTypes = [
            Account::BANK,
            Account::CURRENT_ASSET,
            Account::CASH,
        ];

        $account = Account::where('name', 'like', "%{$accountName}%")
            ->whereIn('account_type', $assetTypes)
            ->first();

        if (!$account) {
            // Fallback to any bank account
            $account = Account::where('account_type', Account::BANK)->first();
        }

        if (!$account) {
            throw new \Exception("No bank account found in IFRS chart of accounts");
        }

        return $account;
    }

    /**
     * Cancel expense
     */
    public function cancel(): bool
    {
        if (in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED])) {
            return false;
        }
        
        $this->update(['status' => self::STATUS_CANCELLED]);
        return true;
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by category
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('expense_date', [$startDate, $endDate]);
    }
}

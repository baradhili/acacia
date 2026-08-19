<?php

namespace App\Models;

use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Models\Vat;
use IFRS\Transactions\JournalEntry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;

class BillPayment extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_VOID = 'void';

    protected $fillable = [
        'payment_number',
        'supplier_id',
        'paid_by',
        'amount',
        'payment_date',
        'payment_method',
        'reference',
        'notes',
        'status',
        'ifrs_payment_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    // Payment method constants
    const METHOD_BANK_TRANSFER = 'bank_transfer';
    const METHOD_CREDIT_CARD = 'credit_card';
    const METHOD_CASH = 'cash';
    const METHOD_CHEQUE = 'cheque';
    const METHOD_OTHER = 'other';

    // IFRS account codes for supplier-payment posting
    const IFRS_BANK_ACCOUNT_CODE = 320; // Operating Account
    const IFRS_DEFAULT_EXPENSE_ACCOUNT_CODE = 8900; // Other Expenses (fallback for legacy items)
    const IFRS_GST_VAT_CODE = 'G'; // Seeded "GST 10%" Vat, linked to account 2200 (GST Payable)

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (empty($payment->payment_number)) {
                $payment->payment_number = self::generatePaymentNumber();
            }
            if (empty($payment->payment_date)) {
                $payment->payment_date = now()->toDateString();
            }
        });
    }

    public static function generatePaymentNumber(): string
    {
        $year = date('Y');
        $lastPayment = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment) {
            preg_match('/SPAY-' . $year . '-(\d+)/', $lastPayment->payment_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('SPAY-%s-%04d', $year, $nextNumber);
    }

    /**
     * Create a payment, retrying if two concurrent requests race on
     * generatePaymentNumber() and produce a duplicate payment_number.
     *
     * The unique() constraint on payment_number is the real source of truth —
     * the loser of a race gets a QueryException (SQLSTATE 23000). Each retry
     * re-enters the creating hook, which regenerates from the now-higher max,
     * so the next attempt picks the following number.
     */
    public static function createWithUniqueNumber(array $attributes): self
    {
        $attempts = 5;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return self::create($attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                if (!self::isUniqueViolation($e) || $i === $attempts) {
                    throw $e;
                }
            }
        }
        // Unreachable — the loop either returns or rethrows.
        throw new \RuntimeException('Unable to create bill payment with a unique number.');
    }

    /**
     * Is the given QueryException a unique-constraint violation? Covers both
     * MySQL (SQLSTATE 23000 / driver code 1062) and SQLite (SQLSTATE 23000 /
     * driver codes 19, 2067) via the shared SQLSTATE.
     */
    protected static function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        // errorInfo[0] is the SQLSTATE; errorInfo[1] is the driver-specific code.
        return ($errorInfo[0] ?? null) === '23000'
            || ($errorInfo[1] ?? null) === 1062;
    }

    public static function paymentMethods(): array
    {
        return [
            self::METHOD_BANK_TRANSFER => 'Bank Transfer',
            self::METHOD_CREDIT_CARD => 'Credit Card',
            self::METHOD_CASH => 'Cash',
            self::METHOD_CHEQUE => 'Cheque',
            self::METHOD_OTHER => 'Other',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BillPaymentAllocation::class);
    }

    /**
     * Get the documents attached to this payment
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get amount allocated to bills
     */
    public function getAllocatedAmountAttribute(): float
    {
        return $this->allocations()->sum('amount');
    }

    /**
     * Get amount remaining to be allocated
     */
    public function getUnallocatedAmountAttribute(): float
    {
        return max(0, (float) $this->amount - $this->allocated_amount);
    }

    /**
     * Check if payment is fully allocated
     */
    public function getIsFullyAllocatedAttribute(): bool
    {
        return $this->unallocated_amount <= 0;
    }

    /**
     * Allocate payment to specific bill.
     *
     * @throws \InvalidArgumentException if $amount is <= 0 or exceeds the
     *         payment's unallocated balance. Callers run inside transactions
     *         so the throw rolls back any partial work cleanly.
     */
    public function allocateToBill(Bill $bill, float $amount): BillPaymentAllocation
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Allocation amount must be greater than zero.');
        }

        $unallocated = $this->unallocated_amount;
        if ($amount > $unallocated) {
            throw new \InvalidArgumentException(
                "Cannot allocate {$amount} to bill {$bill->id}: "
                . "only {$unallocated} unallocated on payment {$this->id}."
            );
        }

        $allocation = BillPaymentAllocation::firstOrCreate(
            [
                'bill_payment_id' => $this->id,
                'bill_id' => $bill->id,
            ],
            [
                'amount' => $amount,
            ]
        );

        // Update allocation amount if it already exists
        if ($allocation->wasRecentlyCreated === false) {
            $allocation->increment('amount', $amount);
        }

        // Update bill status
        $bill->updateStatusFromPayments();

        return $allocation;
    }

    /**
     * Remove allocation from a bill
     */
    public function removeAllocation(Bill $bill): bool
    {
        $allocation = $this->allocations()->where('bill_id', $bill->id)->first();

        if ($allocation) {
            $allocation->delete();
            $bill->updateStatusFromPayments();
            return true;
        }

        return false;
    }

    /**
     * Update status of all allocated bills
     */
    public function updateAllocatedBillsStatus(): void
    {
        foreach ($this->allocations as $allocation) {
            $allocation->bill->updateStatusFromPayments();
        }
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute(): string
    {
        return config('australian.currency.symbol', 'A$') . number_format($this->amount, 2);
    }

    /**
     * Get formatted payment method
     */
    public function getFormattedMethodAttribute(): string
    {
        return self::paymentMethods()[$this->payment_method] ?? $this->payment_method;
    }

    /**
     * Scope for payments by supplier
     */
    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for payments in date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('payment_date', [$startDate, $endDate]);
    }

    /**
     * Post supplier payment to IFRS using the cash-basis double-entry
     * pattern — the mirror image of Payment::postToIFRS():
     *
     *   Cr Bank     (account 320, main account)     — tax-inclusive amount
     *   Dr Expense  (per-account debit lines)       — net amount
     *   Dr GST      (account 2200, auto via addVat) — GST component
     *
     * GST is applied PER LINE ITEM, honouring each bill item's treatment:
     * taxable lines (tax_rate > 0) post tax-inclusive with the seeded
     * "GST 10%" Vat, so the package backs the GST out and debits account
     * 2200 automatically (net-BAS treatment, symmetric with receipts);
     * GST-free lines (tax_rate = 0 — some supplies are GST-free by
     * regulation) post their full amount with no Vat. Each allocation is
     * apportioned across its bill's items by total share.
     *
     * Returns the IFRS transaction id, or null on failure (accounts missing,
     * no entity/reporting period, or any Throwable during posting).
     */
    public function postToIFRS(): ?int
    {
        if ($this->ifrs_payment_id) {
            Log::info("Bill payment {$this->id} already posted to IFRS", ['ifrs_payment_id' => $this->ifrs_payment_id]);
            return (int) $this->ifrs_payment_id;
        }

        try {
            $bankAccount = Account::where('code', self::IFRS_BANK_ACCOUNT_CODE)->first();
            $defaultExpenseAccount = Account::where('code', self::IFRS_DEFAULT_EXPENSE_ACCOUNT_CODE)->first();

            if (!$bankAccount || !$defaultExpenseAccount) {
                Log::error('IFRS accounts not found for bill payment posting', [
                    'bank_code' => self::IFRS_BANK_ACCOUNT_CODE,
                    'expense_code' => self::IFRS_DEFAULT_EXPENSE_ACCOUNT_CODE,
                ]);
                return null;
            }

            // IFRS transactions need an entity (for the reporting period and
            // currency). Prefer the authed user's entity, then fall back to
            // the first entity. Pass entity_id explicitly so posting works in
            // queued jobs where no user is authed.
            $entity = $this->resolveIFRSEntity();
            if (!$entity) {
                Log::error('No IFRS entity available for bill payment posting', ['bill_payment_id' => $this->id]);
                return null;
            }

            // Apportion each allocation across its bill's line items, then
            // aggregate by (expense account, GST treatment). Amounts are
            // computed in cents so the per-allocation shares sum exactly.
            // Key: "{accountId}-{taxable?}" → cents (tax-inclusive share).
            $groups = [];
            foreach ($this->allocations as $allocation) {
                $bill = $allocation->bill()->with('items')->first();
                if (!$bill) {
                    continue;
                }
                $items = $bill->items;
                if ($items->isEmpty()) {
                    continue;
                }

                $billTotalCents = (int) round(((float) $bill->total) * 100);
                $allocationCents = (int) round(((float) $allocation->amount) * 100);
                if ($billTotalCents <= 0 || $allocationCents <= 0) {
                    continue;
                }

                $distributed = 0;
                $lastIndex = $items->count() - 1;
                foreach ($items->values() as $index => $item) {
                    if ($index === $lastIndex) {
                        // Last item takes the remainder so shares sum exactly.
                        $shareCents = $allocationCents - $distributed;
                    } else {
                        $shareCents = (int) round($allocationCents * ((float) $item->total * 100) / $billTotalCents);
                        $distributed += $shareCents;
                    }

                    if ($shareCents <= 0) {
                        continue;
                    }

                    $accountId = $item->expense_account_id ?: $defaultExpenseAccount->id;
                    $taxable = (float) $item->tax_rate > 0;
                    // gst = inclusive amount (vat_inclusive posting backs the
                    // GST out); gstadd = ex-GST amount (package adds GST on
                    // top); free = no GST.
                    $key = $accountId . '-' . ($taxable ? ($item->gst_added ? 'gstadd' : 'gst') : 'free');
                    $groups[$key] = ($groups[$key] ?? 0) + $shareCents;
                }
            }

            if (empty($groups)) {
                Log::error('Nothing to post for bill payment — no allocatable bill items', [
                    'bill_payment_id' => $this->id,
                ]);
                return null;
            }

            // Main account = Bank, credited = true → Cr Bank (asset decreases).
            $journalEntry = new JournalEntry([
                'transaction_date' => $this->payment_date,
                'account_id' => $bankAccount->id,
                'credited' => true,
                'entity_id' => $entity->id,
                'narration' => "Supplier payment: {$this->payment_number} to {$this->supplier?->name}",
                'reference' => $this->payment_number,
            ]);

            $gstVat = Vat::where('code', self::IFRS_GST_VAT_CODE)
                ->where('entity_id', $entity->id)
                ->first();

            foreach ($groups as $key => $cents) {
                [$accountId, $treatment] = explode('-', $key);
                $amount = $cents / 100;
                $vatInclusive = $treatment === 'gst';
                $taxable = in_array($treatment, ['gst', 'gstadd']) && $gstVat;

                // Debit expense line; addLineItem() flips credited to false
                // (the transaction is credited) → Dr Expense. Lines must be
                // persisted before addLineItem() — unsaved items share a null
                // id and the package silently drops all but the first.
                $expenseLine = LineItem::create([
                    'account_id' => (int) $accountId,
                    'amount' => $amount,
                    'quantity' => 1,
                    'vat_inclusive' => $vatInclusive,
                    'entity_id' => $entity->id,
                ]);

                if ($taxable) {
                    // Taxable line: vat_inclusive makes the package debit the
                    // expense account the net amount and auto-debit the GST
                    // account for the GST component.
                    $expenseLine->addVat($gstVat);
                    $expenseLine->save(); // persist the applied vat
                }
                // GST-free line: full amount to the expense account, no Vat.

                $journalEntry->addLineItem($expenseLine);
            }

            // post() saves the transaction AND writes the ledger rows
            // (save() alone leaves it unposted and invisible to reports).
            $journalEntry->post();

            // Store the IFRS transaction id.
            $this->update(['ifrs_payment_id' => $journalEntry->id]);

            Log::info("Bill payment {$this->id} posted to IFRS", [
                'ifrs_payment_id' => $journalEntry->id,
                'amount' => $this->amount,
                'gst_vat' => (bool) $gstVat,
            ]);

            return $journalEntry->id;

        } catch (\Throwable $e) {
            // Throwable (not Exception) so a fatal Error is captured and
            // logged rather than breaking the payment flow that calls this.
            Log::error('Failed to post bill payment to IFRS', [
                'bill_payment_id' => $this->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return null;
        }
    }

    /**
     * Resolve the IFRS entity to post against: the authed user's entity if
     * available, otherwise the first entity. Returns null if none exist.
     */
    protected function resolveIFRSEntity(): ?Entity
    {
        try {
            $user = \Illuminate\Support\Facades\Auth::user();
            if ($user && isset($user->entity) && $user->entity) {
                return $user->entity;
            }
        } catch (\Throwable $e) {
            // Auth not available (e.g. queued job) — fall through to query.
        }

        return Entity::orderBy('id')->first();
    }

    /**
     * Check if payment has been posted to IFRS
     */
    public function getIsPostedToIFRSAttribute(): bool
    {
        return $this->ifrs_payment_id !== null;
    }

    /**
     * Void this payment and restore bill statuses
     */
    public function void(): bool
    {
        if ($this->status === self::STATUS_VOID) {
            return false;
        }

        // Capture affected bills, then delete allocations BEFORE
        // recomputing status — otherwise updateStatusFromPayments() still
        // sees the allocations and treats the bill as paid.
        $billIds = $this->allocations()->pluck('bill_id');
        $this->allocations()->delete();

        foreach ($billIds as $billId) {
            $bill = Bill::find($billId);
            if ($bill) {
                $bill->updateStatusFromPayments();
            }
        }

        // Update status to void
        $this->update(['status' => self::STATUS_VOID]);

        return true;
    }
}

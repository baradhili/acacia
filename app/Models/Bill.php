<?php

namespace App\Models;

use IFRS\Models\Account;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Bill extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_number',
        'supplier_id',
        'project_id',
        'created_by',
        'status',
        'bill_date',
        'due_date',
        'paid_at',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'notes',
        'reference',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // Status constants — mirrors Invoice with `open` (bill received and
    // confirmed, awaiting payment) in place of the AR `sent`.
    const STATUS_DRAFT = 'draft';
    const STATUS_OPEN = 'open';
    const STATUS_PARTIALLY_PAID = 'partially_paid';
    const STATUS_PAID = 'paid';
    const STATUS_OVERDUE = 'overdue';
    const STATUS_CANCELLED = 'cancelled';

    // Valid state transitions
    protected static array $transitions = [
        'draft' => ['open', 'cancelled'],
        'open' => ['partially_paid', 'paid', 'overdue', 'cancelled'],
        'partially_paid' => ['paid', 'overdue'],
        'paid' => [],     // Paid bills cannot be cancelled
        'overdue' => ['partially_paid', 'paid'],
        'cancelled' => [], // Cancelled bills are final
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bill) {
            if (empty($bill->bill_number)) {
                $bill->bill_number = self::generateBillNumber();
            }
            if (empty($bill->status)) {
                $bill->status = self::STATUS_DRAFT;
            }
            if (empty($bill->bill_date)) {
                $bill->bill_date = now()->toDateString();
            }
            if (empty($bill->due_date) && config('australian.invoice_due_days')) {
                $bill->due_date = now()->addDays(config('australian.invoice_due_days', 30))->toDateString();
            }
        });

        // Bill totals are recalculated explicitly via recalculateTotals()
        // (from controllers) and via the BillItem saved/deleted hooks when a
        // line item changes. Intentionally no static::saved auto-recalc hook:
        // recalculateTotals() persists via updateQuietly() inside
        // withoutEvents(), so firing it again on save would be redundant.
    }

    public static function generateBillNumber(): string
    {
        $year = date('Y');
        $lastBill = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastBill) {
            preg_match('/BILL-' . $year . '-(\d+)/', $lastBill->bill_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('BILL-%s-%04d', $year, $nextNumber);
    }

    /**
     * Create a bill, retrying if two concurrent requests race on
     * generateBillNumber() and produce a duplicate bill_number.
     *
     * The unique() constraint on bill_number is the real source of truth —
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
        throw new \RuntimeException('Unable to create bill with a unique number.');
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

    /**
     * IFRS expense accounts (the AP "categories"), for line-item dropdowns.
     * Grouping journals by real chart-of-accounts entries keeps the bill
     * categories and the ledger aligned by construction.
     */
    public static function expenseAccounts(): array
    {
        return Account::whereIn('account_type', [
            Account::OPERATING_EXPENSE,
            Account::DIRECT_EXPENSE,
            Account::OVERHEAD_EXPENSE,
            Account::OTHER_EXPENSE,
        ])
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn ($account) => [$account->id => $account->code . ' — ' . $account->name])
            ->all();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class)->orderBy('sort_order');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BillPaymentAllocation::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Recalculate bill totals from items.
     */
    public function recalculateTotals(): void
    {
        $items = $this->items;

        // Only recalculate if there are items to sum
        if ($items->isEmpty()) {
            return;
        }

        // BillItem.total is the tax-inclusive amount paid (unit prices are
        // entered GST-inclusive; calculateTotals back-calculates the tax).
        // Derive each line's pre-tax value as total - tax_amount so
        // `subtotal + tax_amount == total` holds and SUM(subtotal) reports
        // reflect true pre-GST expenses.
        $subtotal = $items->sum(function ($item) {
            return (float) $item->total - (float) $item->tax_amount;
        });
        $taxAmount = $items->sum('tax_amount');
        $discountAmount = $items->sum('discount_amount');
        $total = $subtotal + $taxAmount;

        // Persist via updateQuietly() inside withoutEvents(). This is the
        // recursion guard: it suppresses the model saved event so any future
        // saved hook added here will not loop.
        static::withoutEvents(function () use ($subtotal, $taxAmount, $discountAmount, $total) {
            $this->updateQuietly([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total' => $total,
            ]);
        });
    }

    /**
     * Get amount paid against this bill
     */
    public function getAmountPaidAttribute(): float
    {
        return $this->allocations()->sum('amount');
    }

    /**
     * Get amount remaining to be paid
     */
    public function getAmountDueAttribute(): float
    {
        return max(0, (float) $this->total - $this->amount_paid);
    }

    /**
     * Get payment percentage
     */
    public function getPaymentPercentageAttribute(): float
    {
        if ($this->total == 0) {
            return 0;
        }
        return ($this->amount_paid / (float) $this->total) * 100;
    }

    /**
     * Check if bill is overdue (past due_date and not paid/cancelled).
     */
    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED])) {
            return false;
        }
        // Compare Carbon-to-Carbon at day granularity: due_date before the
        // start of today means the bill is overdue.
        return $this->due_date && $this->due_date->isBefore(now()->startOfDay());
    }

    /**
     * Check if a transition to the given status is valid
     */
    public function canTransitionTo(string $status): bool
    {
        $allowedTransitions = self::$transitions[$this->status] ?? [];
        return in_array($status, $allowedTransitions);
    }

    /**
     * Get valid transitions from current status
     */
    public function getValidTransitions(): array
    {
        return self::$transitions[$this->status] ?? [];
    }

    /**
     * Transition to a new status with validation.
     */
    public function transitionTo(string $status): bool
    {
        if (!$this->canTransitionTo($status)) {
            return false;
        }

        // Single update: stamp paid_at together with the status when fully paid.
        $payload = ['status' => $status];
        if ($status === self::STATUS_PAID) {
            $payload['paid_at'] = now();
        }
        $this->update($payload);

        return true;
    }

    /**
     * Mark bill as open — routes through the state machine so the
     * transition is validated.
     */
    public function markAsOpen(): bool
    {
        return $this->transitionTo(self::STATUS_OPEN);
    }

    /**
     * Mark bill as overdue
     */
    public function markAsOverdue(): bool
    {
        return $this->transitionTo(self::STATUS_OVERDUE);
    }

    /**
     * Cancel bill
     */
    public function cancel(): bool
    {
        return $this->transitionTo(self::STATUS_CANCELLED);
    }

    /**
     * Check if bill can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->canTransitionTo(self::STATUS_CANCELLED);
    }

    /**
     * Check if bill can be edited
     */
    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Scope for draft bills
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope for outstanding bills (open, partially_paid, overdue)
     */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [
            self::STATUS_OPEN,
            self::STATUS_PARTIALLY_PAID,
            self::STATUS_OVERDUE,
        ]);
    }

    /**
     * Scope for overdue bills: either already flagged overdue, or any
     * open/partially_paid bill past its due_date that still has an
     * outstanding balance (amount_paid < total). The balance check excludes
     * bills that are effectively paid but whose status hasn't been flipped
     * to paid yet, so they don't show as overdue forever.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE)
            ->orWhere(function ($q) {
                $q->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_PAID])
                  ->where('due_date', '<', now()->toDateString())
                  // For bills with a positive total, require an outstanding
                  // balance (total > sum of allocations). Zero-total bills
                  // fall through (the status/due_date checks alone apply).
                  ->where(function ($q) {
                      $q->where('total', '<=', 0)
                        ->orWhereRaw(
                            'bills.total - COALESCE(('
                            . 'SELECT SUM(amount) FROM bill_payment_allocations'
                            . ' WHERE bill_payment_allocations.bill_id = bills.id'
                            . '), 0) > 0'
                        );
                  });
            });
    }

    /**
     * Scope for paid bills
     */
    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /**
     * Get Australian formatted total
     */
    public function getFormattedTotalAttribute(): string
    {
        return config('australian.currency.symbol', 'A$') . number_format($this->total, 2);
    }

    /**
     * Get days until due (negative if overdue)
     */
    public function getDaysUntilDueAttribute(): int
    {
        if (!$this->due_date) {
            return 0;
        }
        return now()->diffInDays($this->due_date, false);
    }

    /**
     * Update bill status based on payments.
     *
     * When payments are removed and amountPaid drops to 0, re-derive the
     * payable status from the bill's own state instead of blindly forcing
     * STATUS_OPEN: a past-due bill regains STATUS_OVERDUE. Unlike
     * Invoice::updateStatusFromPayments(), STATUS_PAID is NOT excluded
     * here — a paid bill whose payments were removed/voided must revert
     * (paid → overdue if past due, else open; paid_at cleared). Only
     * draft and cancelled bills are never clobbered.
     */
    public function updateStatusFromPayments(): void
    {
        // Read totals fresh from the database: callers may hold a stale
        // instance whose totals were persisted by the BillItem saved-hook on
        // a different instance (bill created + items added + payment
        // allocated in one flow).
        if ($this->exists) {
            $this->refresh();
        }

        $amountPaid = $this->amount_paid;
        $total = (float) $this->total;

        if ($amountPaid >= $total && $total > 0) {
            // Fully paid
            if ($this->status !== self::STATUS_PAID) {
                $this->update([
                    'status' => self::STATUS_PAID,
                    'paid_at' => now(),
                ]);
            }
        } elseif ($amountPaid > 0) {
            // Partially paid
            if ($this->status !== self::STATUS_PARTIALLY_PAID) {
                $this->update(['status' => self::STATUS_PARTIALLY_PAID]);
            }
        } else {
            // No payments: never clobber draft/cancelled; otherwise
            // overdue (past due_date) beats open. Evaluate overdue
            // directly, not via is_overdue — that accessor returns false
            // while the model is still marked paid, which we are about to
            // revert from.
            if (!in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CANCELLED])) {
                $overdue = $this->due_date && $this->due_date->isBefore(now()->startOfDay());
                $this->update([
                    'status' => $overdue ? self::STATUS_OVERDUE : self::STATUS_OPEN,
                    'paid_at' => null,
                ]);
            }
        }
    }

    /**
     * Check if bill has been fully paid
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID || $this->amount_due <= 0;
    }

    /**
     * Check if bill has outstanding balance
     */
    public function hasOutstandingBalance(): bool
    {
        return $this->status !== self::STATUS_PAID && $this->amount_due > 0;
    }
}

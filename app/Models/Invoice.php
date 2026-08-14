<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'client_id',
        'project_id',
        'purchase_order_id',
        'created_by',
        'status',
        'issue_date',
        'due_date',
        'paid_at',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'notes',
        'terms',
        'ifrs_invoice_id',
        'is_recurring',
        'recurring_frequency',
        'next_recurring_date',
        'parent_invoice_id',
        'sent_at',
        'viewed_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'is_recurring' => 'boolean',
        'next_recurring_date' => 'date',
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_SENT = 'sent';
    const STATUS_PARTIALLY_PAID = 'partially_paid';
    const STATUS_PAID = 'paid';
    const STATUS_OVERDUE = 'overdue';
    const STATUS_CANCELLED = 'cancelled';

    // Recurring frequencies
    const RECURRING_DAILY = 'daily';
    const RECURRING_WEEKLY = 'weekly';
    const RECURRING_MONTHLY = 'monthly';
    const RECURRING_YEARLY = 'yearly';

    // Valid state transitions
    protected static array $transitions = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['partially_paid', 'paid', 'overdue', 'cancelled'],
        'partially_paid' => ['paid', 'overdue'],
        'paid' => [],  // Paid invoices cannot be cancelled
        'overdue' => ['partially_paid', 'paid'],
        'cancelled' => [],  // Cancelled invoices are final
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::generateInvoiceNumber();
            }
            if (empty($invoice->status)) {
                $invoice->status = self::STATUS_DRAFT;
            }
            if (empty($invoice->issue_date)) {
                $invoice->issue_date = now()->toDateString();
            }
            if (empty($invoice->due_date) && config('australian.invoice_due_days')) {
                $invoice->due_date = now()->addDays(config('australian.invoice_due_days', 30))->toDateString();
            }
        });

        // Invoice totals are recalculated explicitly via recalculateTotals()
        // (from controllers, Estimate::convertToInvoice, etc.) and via the
        // InvoiceItem saved/deleted hooks when a line item changes. There is
        // intentionally no static::saved auto-recalc hook here: recalculateTotals()
        // persists via updateQuietly() inside withoutEvents(), so firing it
        // again on save would be both redundant and (without the quiet save) a
        // recursion risk. If auto-recalc-on-save is ever needed, add it
        // deliberately with a real guard — the old `preventRecalculation` flag
        // was never set anywhere and protected nothing.
    }

    public static function generateInvoiceNumber(): string
    {
        $year = date('Y');
        $lastInvoice = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastInvoice) {
            preg_match('/INV-' . $year . '-(\d+)/', $lastInvoice->invoice_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('INV-%s-%04d', $year, $nextNumber);
    }

    /**
     * Create an invoice, retrying if two concurrent requests race on
     * generateInvoiceNumber() and produce a duplicate invoice_number.
     *
     * The unique() constraint on invoice_number is the real source of truth —
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
        throw new \RuntimeException('Unable to create invoice with a unique number.');
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, PaymentAllocation::class, 'invoice_id', 'id', 'id', 'payment_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'parent_invoice_id');
    }

    public function childInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'parent_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Recalculate invoice totals from items
     */
    public function recalculateTotals(): void
    {
        $items = $this->items;

        // Only recalculate if there are items to sum
        if ($items->isEmpty()) {
            return;
        }

        // InvoiceItem.total is tax-inclusive (calculated in calculateTotals),
        // so derive subtotal as the pre-tax line amount (quantity * unit_price,
        // less discount) and rebuild total as subtotal + tax. Storing the
        // pre-tax value here keeps `subtotal + tax_amount == total` and makes
        // SUM(subtotal) reports reflect true pre-GST revenue.
        $subtotal = $items->sum(function ($item) {
            return ($item->quantity * $item->unit_price) - $item->discount_amount;
        });
        $taxAmount = $items->sum('tax_amount');
        $discountAmount = $items->sum('discount_amount');
        $total = $subtotal + $taxAmount;

        // Persist via updateQuietly() inside withoutEvents(). This is the
        // recursion guard: it suppresses the model saved event so the
        // (now-removed) auto-recalc hook could not re-enter, and any future
        // saved hook added here will not loop. Do NOT replace this with a
        // plain update()/save() without a real re-entry guard.
        static::withoutEvents(function () use ($subtotal, $taxAmount, $discountAmount, $total) {
            $this->updateQuietly([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total' => $total,
            ]);
        });

        // Keep the linked PO's consumed amount in sync when totals change.
        // This runs silently (updateQuietly above) so the InvoiceObserver
        // would otherwise miss total-only changes from line-item edits.
        $this->refreshPurchaseOrderUsedAmount();
    }

    /**
     * Recalculate the linked purchase order's used amount, if any.
     */
    protected function refreshPurchaseOrderUsedAmount(): void
    {
        if ($this->purchase_order_id && $this->purchaseOrder) {
            $this->purchaseOrder->recalculateUsedAmount();
        }
    }

    /**
     * Get amount paid against this invoice
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
     * Check if invoice is overdue
     */
    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED])) {
            return false;
        }
        return $this->due_date && $this->due_date->lt(now()->toDateString());
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
     * Mark invoice as sent — routes through the state machine so the
     * transition is validated, then stamps sent_at on success.
     */
    public function markAsSent(): bool
    {
        if (!$this->transitionTo(self::STATUS_SENT)) {
            return false;
        }
        $this->update(['sent_at' => now()]);
        return true;
    }

    /**
     * Mark invoice as overdue
     */
    public function markAsOverdue(): bool
    {
        return $this->transitionTo(self::STATUS_OVERDUE);
    }

    /**
     * Cancel invoice
     */
    public function cancel(): bool
    {
        return $this->transitionTo(self::STATUS_CANCELLED);
    }

    /**
     * Check if invoice can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->canTransitionTo(self::STATUS_CANCELLED);
    }

    /**
     * Check if invoice can be edited
     */
    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Scope for draft invoices
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope for outstanding invoices (sent, partially_paid, overdue)
     */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [
            self::STATUS_SENT,
            self::STATUS_PARTIALLY_PAID,
            self::STATUS_OVERDUE,
        ]);
    }

    /**
     * Scope for overdue invoices: either already flagged overdue, or any
     * sent/partially_paid invoice past its due_date that still has an
     * outstanding balance (amount_paid < total). The balance check excludes
     * invoices that are effectively paid but whose status hasn't been flipped
     * to paid yet, so they don't show as overdue forever. Zero-total invoices
     * (no items / credit notes) are left to the status-based path.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE)
            ->orWhere(function ($q) {
                $q->whereIn('status', [self::STATUS_SENT, self::STATUS_PARTIALLY_PAID])
                  ->where('due_date', '<', now()->toDateString())
                  // For invoices with a positive total, require an outstanding
                  // balance (total > sum of allocations). Zero-total invoices
                  // fall through (the status/due_date checks alone apply).
                  ->where(function ($q) {
                      $q->where('total', '<=', 0)
                        ->orWhereRaw(
                            'invoices.total - COALESCE(('
                            . 'SELECT SUM(amount) FROM payment_allocations'
                            . ' WHERE payment_allocations.invoice_id = invoices.id'
                            . '), 0) > 0'
                        );
                  });
            });
    }

    /**
     * Scope for paid invoices
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
        return 'A$' . number_format($this->total, 2);
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
     * Update invoice status based on payments.
     *
     * When payments are removed and amountPaid drops to 0, re-derive the
     * payable status from the invoice's own state instead of blindly forcing
     * STATUS_SENT: a past-due invoice regains STATUS_OVERDUE, and a draft (or
     * cancelled/paid) invoice is never clobbered.
     */
    public function updateStatusFromPayments(): void
    {
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
            // No payments: never clobber draft/cancelled/paid; otherwise
            // overdue (past due_date) beats sent.
            if (!in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CANCELLED, self::STATUS_PAID])) {
                $this->update([
                    'status' => $this->is_overdue ? self::STATUS_OVERDUE : self::STATUS_SENT,
                    'paid_at' => null,
                ]);
            }
        }
    }

    /**
     * Check if invoice has been fully paid
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID || $this->amount_due <= 0;
    }

    /**
     * Check if invoice has outstanding balance
     */
    public function hasOutstandingBalance(): bool
    {
        return $this->status !== self::STATUS_PAID && $this->amount_due > 0;
    }
}

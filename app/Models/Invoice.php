<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    const STATUS_VIEWED = 'viewed';
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
        'sent' => ['viewed', 'partially_paid', 'paid', 'overdue', 'cancelled'],
        'viewed' => ['partially_paid', 'paid', 'overdue', 'cancelled'],
        'partially_paid' => ['paid', 'overdue', 'cancelled'],
        'paid' => [],  // Paid invoices cannot be cancelled
        'overdue' => ['partially_paid', 'paid', 'cancelled'],
        'cancelled' => [],
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

        // Recalculate when items change (not via recalculateTotals to avoid recursion)
        static::saved(function ($invoice) {
            if (!isset($invoice->preventRecalculation)) {
                $invoice->recalculateTotals();
            }
        });
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

        // InvoiceItem.total includes tax (calculated in calculateTotals)
        // So we use sum of totals directly, not adding tax again
        $subtotal = $items->sum('total');
        $taxAmount = $items->sum('tax_amount');
        $discountAmount = $items->sum('discount_amount');
        $total = $subtotal;

        // Use withoutEvents to prevent the saved event from triggering recalculateTotals again
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
     * Transition to a new status with validation
     */
    public function transitionTo(string $status): bool
    {
        if (!$this->canTransitionTo($status)) {
            return false;
        }

        $this->update(['status' => $status]);
        
        // Update paid_at when status changes to paid
        if ($status === self::STATUS_PAID) {
            $this->update(['paid_at' => now()]);
        }

        // Auto-update status based on payment if partially paid
        if ($status === self::STATUS_PARTIALLY_PAID && $this->amount_due <= 0) {
            $this->update(['status' => self::STATUS_PAID, 'paid_at' => now()]);
        }

        return true;
    }

    /**
     * Mark invoice as sent
     */
    public function markAsSent(): bool
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);
        return true;
    }

    /**
     * Mark invoice as viewed
     */
    public function markAsViewed(): bool
    {
        if ($this->status === self::STATUS_SENT) {
            $this->update([
                'status' => self::STATUS_VIEWED,
                'viewed_at' => now(),
            ]);
        } elseif ($this->status === self::STATUS_DRAFT) {
            $this->update([
                'status' => self::STATUS_VIEWED,
                'viewed_at' => now(),
            ]);
        }
        return true;
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
     * Scope for outstanding invoices (sent, viewed, partially_paid, overdue)
     */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [
            self::STATUS_SENT,
            self::STATUS_VIEWED,
            self::STATUS_PARTIALLY_PAID,
            self::STATUS_OVERDUE,
        ]);
    }

    /**
     * Scope for overdue invoices
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE)
            ->orWhere(function ($q) {
                $q->whereIn('status', [self::STATUS_SENT, self::STATUS_VIEWED, self::STATUS_PARTIALLY_PAID])
                  ->where('due_date', '<', now()->toDateString());
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
     * Update invoice status based on payments
     */
    public function updateStatusFromPayments(): void
    {
        if ($this->status === self::STATUS_CANCELLED || $this->status === self::STATUS_PAID) {
            return;
        }

        $amountPaid = $this->amount_paid;
        $total = (float) $this->total;

        if ($amountPaid <= 0) {
            // No payments - status remains as is (could be sent, viewed, or overdue)
            if ($this->is_overdue && $this->status !== self::STATUS_OVERDUE) {
                $this->update(['status' => self::STATUS_OVERDUE]);
            }
        } elseif ($amountPaid >= $total) {
            // Fully paid
            $this->update([
                'status' => self::STATUS_PAID,
                'paid_at' => now(),
            ]);
        } else {
            // Partially paid
            if ($this->status !== self::STATUS_PARTIALLY_PAID) {
                $this->update(['status' => self::STATUS_PARTIALLY_PAID]);
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
        return $this->amount_due > 0;
    }
}

<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * A commercial document a client issues to the firm — the billing
 * budget invoices are drawn down against. Two kinds share this one
 * pipeline (invoice used_amount sync, 80%/100% utilization sweeps,
 * project claiming): a purchase_order has a fixed period and fixed
 * budgeted_amount, while a contract's budget is implied from its
 * terms — rate (hourly, inc GST) × business days (Mon–Fri, inclusive
 * of both start and end) × allocation% × 8 — recomputed on every save
 * so budgeted_amount is always the current terms' value, in inc-GST
 * dollars to match the invoice totals used_amount sums. Contracts may
 * be amended while open/partially_used (each amendment snapshotting
 * old → new terms in PurchaseOrderAmendment); purchase orders are
 * editable in draft only. used_amount is the sum of non-draft/
 * non-cancelled invoice totals, kept in step by InvoiceObserver;
 * po:check-utilization notifies admins at 80%/100%. Status machine
 * draft → open → partially_used → completed/cancelled. At most one
 * project may claim a PO: unique index on projects.purchase_order_id,
 * claimed under a row lock.
 */
class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'type',
        'title',
        'description',
        'budgeted_amount',
        'rate',
        'allocation',
        'used_amount',
        'status',
        'start_date',
        'end_date',
        'utilization_notified_80',
        'utilization_notified_100',
    ];

    protected $casts = [
        'budgeted_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'allocation' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'utilization_notified_80' => 'boolean',
        'utilization_notified_100' => 'boolean',
    ];

    // Document-type constants (a contract's budget is implied from
    // rate × business days × allocation × 8; a purchase order's is fixed)
    const TYPE_PURCHASE_ORDER = 'purchase_order';

    const TYPE_CONTRACT = 'contract';

    // Standard working day used to convert a contract's business days
    // into budgetable hours.
    const HOURS_PER_DAY = 8;

    // Status constants
    const STATUS_DRAFT = 'draft';

    const STATUS_OPEN = 'open';

    const STATUS_PARTIALLY_USED = 'partially_used';

    const STATUS_COMPLETED = 'completed';

    const STATUS_CANCELLED = 'cancelled';

    // Valid state transitions
    protected static array $transitions = [
        'draft' => ['open', 'cancelled'],
        'open' => ['partially_used', 'completed', 'cancelled'],
        'partially_used' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($po) {
            if (empty($po->po_number)) {
                $po->po_number = self::generatePoNumber($po->type ?: self::TYPE_PURCHASE_ORDER);
            }
            if (empty($po->status)) {
                $po->status = self::STATUS_DRAFT;
            }
        });

        // A contract's budgeted_amount is never entered — it is always
        // the current terms' implied value, whatever writes them (form,
        // amendment, tinker).
        static::saving(function ($po) {
            if ($po->type === self::TYPE_CONTRACT
                && $po->rate !== null
                && $po->allocation !== null
                && $po->start_date !== null
                && $po->end_date !== null) {
                $po->budgeted_amount = $po->implied_budget;
            }
        });
    }

    /**
     * Next document number for the given type, sequenced per prefix
     * per year: PO-YYYY-NNNN for purchase orders, CT-YYYY-NNNN for
     * contracts.
     */
    public static function generatePoNumber(?string $type = null): string
    {
        $prefix = $type === self::TYPE_CONTRACT ? 'CT' : 'PO';
        $year = date('Y');
        $lastPo = self::where('po_number', 'like', $prefix.'-'.$year.'-%')
            ->orderByDesc('po_number')
            ->first();

        $nextNumber = 1;
        if ($lastPo) {
            preg_match('/'.$prefix.'-'.$year.'-(\d+)/', $lastPo->po_number, $matches);
            if (isset($matches[1])) {
                $nextNumber = ((int) $matches[1]) + 1;
            }
        }

        return sprintf('%s-%s-%04d', $prefix, $year, $nextNumber);
    }

    public function isContract(): bool
    {
        return $this->type === self::TYPE_CONTRACT;
    }

    /**
     * Weekdays (Mon–Fri) from start through end, both endpoints
     * included — e.g. a Monday to the following Monday is 6 business
     * days. A plain day-walk, not week arithmetic: contract spans are
     * at most a few thousand days and obvious correctness beats the
     * closed-form version here.
     */
    public static function businessDaysInclusive(CarbonInterface $start, CarbonInterface $end): int
    {
        if ($end->startOfDay()->lt($start->startOfDay())) {
            return 0;
        }

        $days = 0;
        $cursor = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * Business days spanned by the current start/end dates (null when
     * either date is missing — purchase orders may have none).
     */
    public function getBusinessDaysAttribute(): ?int
    {
        if (! $this->start_date || ! $this->end_date) {
            return null;
        }

        return self::businessDaysInclusive($this->start_date, $this->end_date);
    }

    /**
     * The contract's implied budget from its current terms:
     * rate (inc GST) × business days × allocation% × 8 hours.
     * Returns 0 for incomplete terms — validation keeps contracts
     * from being saved that way, so this only guards stray writes.
     */
    public function getImpliedBudgetAttribute(): float
    {
        if (! $this->isContract()
            || $this->rate === null
            || $this->allocation === null
            || ! $this->start_date
            || ! $this->end_date) {
            return 0.0;
        }

        $days = self::businessDaysInclusive($this->start_date, $this->end_date);

        return round(
            (float) $this->rate * $days * ((float) $this->allocation / 100) * self::HOURS_PER_DAY,
            2
        );
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * The invoices that consume this purchase order's budget.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the documents attached to this purchase order
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * The amendments recorded against this contract, oldest first —
     * empty for purchase-order-type documents (never amendable).
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(PurchaseOrderAmendment::class)->orderBy('id');
    }

    /**
     * Get remaining budget
     */
    public function getRemainingAttribute(): float
    {
        return max(0, (float) $this->budgeted_amount - (float) $this->used_amount);
    }

    /**
     * Get utilization percentage
     */
    public function getUtilizationAttribute(): float
    {
        if ($this->budgeted_amount == 0) {
            return 0;
        }

        return ((float) $this->used_amount / (float) $this->budgeted_amount) * 100;
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
     * Check if PO can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->canTransitionTo(self::STATUS_CANCELLED);
    }

    /**
     * Check if PO can be activated
     */
    public function canBeActivated(): bool
    {
        return $this->canTransitionTo(self::STATUS_OPEN);
    }

    /**
     * Recalculate used amount from invoices issued against this PO.
     *
     * Draft invoices (not yet issued) and cancelled invoices (voided)
     * are excluded; everything else counts as consumed budget.
     */
    public function recalculateUsedAmount(): void
    {
        $total = $this->invoices()
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED])
            ->sum('total');

        $this->update(['used_amount' => $total]);
        $this->updateStatus();
    }

    /**
     * Update status based on utilization (only for open/partially_used states)
     */
    public function updateStatus(): void
    {
        // Only update status automatically for open or partially_used POs
        if (! in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_USED])) {
            return;
        }

        $utilization = $this->utilization;

        if ($utilization >= 100) {
            $this->transitionTo(self::STATUS_COMPLETED);
        } elseif ($utilization > 0 && $this->status === self::STATUS_OPEN) {
            $this->transitionTo(self::STATUS_PARTIALLY_USED);
        }
    }

    /**
     * Invoicing draws down a live PO's budget: only open and
     * partially_used POs qualify — drafts are not active yet, and
     * completed or cancelled POs are finished.
     */
    public function canBeInvoiced(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_USED]);
    }

    /**
     * Only contracts can be amended, and only while live — a draft
     * contract is edited in place instead, and completed/cancelled
     * documents must be reopened first. Purchase orders are never
     * amendable; their budget is fixed for life.
     */
    public function canBeAmended(): bool
    {
        return $this->isContract()
            && in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_USED]);
    }

    /**
     * Amend a live contract's terms: snapshot old → new rate,
     * allocation, period and implied budget into a numbered amendment
     * record ({po_number}-A1, -A2, …), apply the new terms (the saving
     * hook recomputes budgeted_amount) and re-evaluate status against
     * the new budget — all atomically. Returns null when the document
     * is not amendable; caller-side validation supplies the terms.
     *
     * @param  array{rate: mixed, allocation: mixed, start_date: mixed, end_date: mixed}  $terms
     */
    public function amend(array $terms, ?string $reason = null, ?User $user = null): ?PurchaseOrderAmendment
    {
        if (! $this->canBeAmended()) {
            return null;
        }

        return DB::transaction(function () use ($terms, $reason, $user) {
            $previous = [
                'previous_rate' => $this->rate,
                'previous_allocation' => $this->allocation,
                'previous_start_date' => $this->start_date,
                'previous_end_date' => $this->end_date,
                'previous_budgeted_amount' => $this->budgeted_amount,
            ];

            $this->fill([
                'rate' => $terms['rate'],
                'allocation' => $terms['allocation'],
                'start_date' => $terms['start_date'],
                'end_date' => $terms['end_date'],
            ]);

            $amendment = $this->amendments()->create($previous + [
                'amendment_number' => $this->po_number.'-A'.($this->amendments()->count() + 1),
                'new_rate' => $this->rate,
                'new_allocation' => $this->allocation,
                'new_start_date' => $this->start_date,
                'new_end_date' => $this->end_date,
                'new_budgeted_amount' => $this->implied_budget,
                'reason' => $reason,
            ]);
            $amendment->user_id = $user?->id;
            $amendment->save();

            $this->save();
            $this->updateStatus();

            return $amendment;
        });
    }

    /**
     * Transition to a new status with validation
     */
    public function transitionTo(string $status): bool
    {
        if (! $this->canTransitionTo($status)) {
            return false;
        }

        $this->update(['status' => $status]);

        return true;
    }

    /**
     * Activate PO (draft → open)
     */
    public function activate(): bool
    {
        return $this->transitionTo(self::STATUS_OPEN);
    }

    /**
     * Cancel PO (draft/open/partially_used → cancelled)
     */
    public function cancel(): bool
    {
        return $this->transitionTo(self::STATUS_CANCELLED);
    }

    /**
     * Mark as completed manually
     */
    public function complete(): bool
    {
        return $this->transitionTo(self::STATUS_COMPLETED);
    }

    /**
     * Reopen a completed or cancelled PO back to draft
     */
    public function reopen(): bool
    {
        if (in_array($this->status, [self::STATUS_DRAFT])) {
            return false;
        }

        // Reset notification flags when reopening and set to open
        $this->update([
            'status' => self::STATUS_OPEN,
            'utilization_notified_80' => false,
            'utilization_notified_100' => false,
        ]);

        return true;
    }

    /**
     * Check if 80% notification should be sent
     */
    public function shouldNotify80Percent(): bool
    {
        return $this->utilization >= 80
            && $this->utilization < 100
            && ! $this->utilization_notified_80;
    }

    /**
     * Check if 100% notification should be sent
     */
    public function shouldNotify100Percent(): bool
    {
        return $this->utilization >= 100 && ! $this->utilization_notified_100;
    }

    /**
     * Mark 80% notification as sent
     */
    public function markNotified80(): void
    {
        $this->update(['utilization_notified_80' => true]);
    }

    /**
     * Mark 100% notification as sent
     */
    public function markNotified100(): void
    {
        $this->update(['utilization_notified_100' => true]);
    }

    /**
     * Scope for open POs
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            self::STATUS_OPEN,
            self::STATUS_PARTIALLY_USED,
        ]);
    }

    /**
     * Scope for active POs (can receive time allocations)
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_OPEN,
            self::STATUS_PARTIALLY_USED,
        ]);
    }
}

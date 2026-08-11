<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'po_number',
        'client_id',
        'project_id',
        'title',
        'description',
        'budgeted_amount',
        'used_amount',
        'status',
        'start_date',
        'end_date',
        'utilization_notified_80',
        'utilization_notified_100',
    ];

    protected $casts = [
        'budgeted_amount' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'utilization_notified_80' => 'boolean',
        'utilization_notified_100' => 'boolean',
    ];

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
                $po->po_number = self::generatePoNumber();
            }
            if (empty($po->status)) {
                $po->status = self::STATUS_DRAFT;
            }
        });
    }

    public static function generatePoNumber(): string
    {
        $year = date('Y');
        $lastPo = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPo) {
            preg_match('/PO-' . $year . '-(\d+)/', $lastPo->po_number, $matches);
            $nextNumber = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('PO-%s-%04d', $year, $nextNumber);
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
        if (!in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_USED])) {
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
     * Transition to a new status with validation
     */
    public function transitionTo(string $status): bool
    {
        if (!$this->canTransitionTo($status)) {
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
            && !$this->utilization_notified_80;
    }

    /**
     * Check if 100% notification should be sent
     */
    public function shouldNotify100Percent(): bool
    {
        return $this->utilization >= 100 && !$this->utilization_notified_100;
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

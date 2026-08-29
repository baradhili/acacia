<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'purchase_order_id',
        'entry_date',
        'start_time',
        'end_time',
        'hours',
        'rate',
        'billable',
        'description',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'approved_at' => 'datetime',
        'hours' => 'decimal:2',
        'rate' => 'decimal:2',
        'billable' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        // Timed entries derive their hours; manual entries keep whatever
        // was entered. Breaks are persisted child rows, so during a fresh
        // create they don't exist yet and the span stands until they are
        // saved (their hooks then trigger recalculateHours()).
        static::saving(function ($entry) {
            // Defensive default for model-level creates that only set a
            // start_time (the controller always sets entry_date explicitly).
            if (!$entry->entry_date && $entry->start_time) {
                $entry->entry_date = $entry->start_time->toDateString();
            }

            if ($entry->start_time && $entry->end_time) {
                $entry->hours = $entry->calculateHours();
            }
        });
    }

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function breaks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TimeEntryBreak::class)->orderBy('start_time');
    }

    /**
     * The invoice item consuming this entry, if it has been invoiced.
     * Items on cancelled invoices are excluded so cancelling an
     * invoice releases its time entries for re-invoicing.
     */
    public function invoiceItem(): HasOne
    {
        return $this->hasOne(InvoiceItem::class)
            ->whereHas('invoice', fn ($q) => $q->whereNot('status', 'cancelled'));
    }

    /**
     * Recompute hours from (end − start − breaks) and persist. Called by
     * the break hooks after break rows change, and by the controller after
     * syncing breaks. No-op for manual (untimed) entries.
     */
    public function recalculateHours(): void
    {
        if (!$this->start_time || !$this->end_time) {
            return;
        }

        $hours = $this->calculateHours();
        if (abs((float) $this->hours - $hours) >= 0.001) {
            $this->updateQuietly(['hours' => $hours]);
        }
    }

    /**
     * Calculate hours from start/end times minus persisted breaks
     */
    public function calculateHours(): float
    {
        if ($this->start_time && $this->end_time) {
            $span = $this->start_time->floatDiffInHours($this->end_time);
            $breakMinutes = $this->breaks()->get()->sum(fn ($b) => $b->durationMinutes());

            return round(max($span - $breakMinutes / 60, 0), 2);
        }
        return (float) $this->hours;
    }

    /**
     * Get total amount for this entry
     */
    public function getTotalAttribute(): float
    {
        return round($this->hours * $this->effective_rate, 2);
    }

    /**
     * Get effective rate
     */
    public function getEffectiveRateAttribute(): float
    {
        if ($this->rate) {
            return (float) $this->rate;
        }
        if ($this->project_id && $this->project) {
            return (float) ($this->project->hourly_rate ?? 0);
        }
        return 0;
    }

    /**
     * Submit for approval
     */
    public function submit(): void
    {
        $this->update(['status' => self::STATUS_SUBMITTED]);
    }

    /**
     * Approve entry
     */
    public function approve(int $approverId): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }

    /**
     * Reject entry
     */
    public function reject(int $approverId, string $reason): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by' => $approverId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Scope for pending approval
     */
    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    /**
     * Scope for approved entries
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope for billable entries
     */
    public function scopeBillable($query)
    {
        return $query->where('billable', true);
    }

    /**
     * Scope for user's entries in date range
     */
    public function scopeForUserAndPeriod($query, int $userId, Carbon $start, Carbon $end)
    {
        return $query->where('user_id', $userId)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()]);
    }
}

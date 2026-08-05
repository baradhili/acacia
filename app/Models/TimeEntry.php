<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'purchase_order_id',
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

        // Calculate hours from start/end times when saving
        static::saving(function ($entry) {
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

    /**
     * Calculate hours from start/end times if not set
     */
    public function calculateHours(): float
    {
        if ($this->start_time && $this->end_time) {
            return round($this->start_time->floatDiffInHours($this->end_time), 2);
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
            ->whereBetween('start_time', [$start, $end]);
    }
}

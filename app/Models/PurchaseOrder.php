<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($po) {
            if (empty($po->po_number)) {
                $po->po_number = self::generatePoNumber();
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
     * Recalculate used amount from time entries
     */
    public function recalculateUsedAmount(): void
    {
        $total = $this->timeEntries()
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->sum('total');

        $this->update(['used_amount' => $total]);
        $this->updateStatus();
    }

    /**
     * Update status based on utilization
     */
    public function updateStatus(): void
    {
        $utilization = $this->utilization;

        if ($utilization >= 100) {
            $this->update(['status' => self::STATUS_COMPLETED]);
        } elseif ($utilization > 0) {
            $this->update(['status' => self::STATUS_PARTIALLY_USED]);
        }
    }

    /**
     * Activate PO
     */
    public function activate(): void
    {
        $this->update(['status' => self::STATUS_OPEN]);
    }

    /**
     * Cancel PO
     */
    public function cancel(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
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
}

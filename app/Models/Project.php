<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'purchase_order_id',
        'name',
        'description',
        'budget_hours',
        'budget_amount',
        'hourly_rate',
        'status',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'budget_hours' => 'decimal:2',
        'budget_amount' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_ON_HOLD = 'on_hold';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(ProjectStaff::class);
    }

    /**
     * Get total hours logged
     */
    public function getTotalHoursAttribute(): float
    {
        return (float) $this->timeEntries()->sum('hours');
    }

    /**
     * Get total amount spent
     */
    public function getTotalCostAttribute(): float
    {
        return (float) $this->timeEntries()->sum('total');
    }

    /**
     * Get budget utilization percentage
     */
    public function getBudgetUtilizationAttribute(): float
    {
        if (!$this->budget_hours || $this->budget_hours == 0) {
            return 0;
        }
        return ($this->total_hours / $this->budget_hours) * 100;
    }

    /**
     * Get remaining budget hours
     */
    public function getRemainingHoursAttribute(): float
    {
        return max(0, (float) $this->budget_hours - $this->total_hours);
    }

    /**
     * Get remaining budget amount
     */
    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->budget_amount - $this->total_cost);
    }

    /**
     * Scope for active projects
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}

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

    protected static function booted()
    {
        parent::booted();

        // Mirror the project→PO link onto the PO row so "available PO"
        // filtering and PO screens see the linkage from both sides:
        // clear any PO still pointing at this project, then point the
        // linked PO back. Mass updates keep this loop-free.
        static::saved(function (Project $project) {
            if (! $project->wasRecentlyCreated && ! $project->wasChanged('purchase_order_id')) {
                return;
            }

            PurchaseOrder::where('project_id', $project->id)
                ->whereKeyNot($project->purchase_order_id ?? 0)
                ->update(['project_id' => null]);

            if ($project->purchase_order_id) {
                PurchaseOrder::whereKey($project->purchase_order_id)
                    ->update(['project_id' => $project->id]);
            }
        });
    }

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
        if (! $this->budget_hours || $this->budget_hours == 0) {
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

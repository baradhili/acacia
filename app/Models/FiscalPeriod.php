<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalPeriod extends Model
{
    use HasFactory;

    const TYPE_MONTHLY = 'monthly';
    const TYPE_QUARTERLY = 'quarterly';
    const TYPE_ANNUAL = 'annual';

    protected $fillable = [
        'name',
        'year',
        'period_type',
        'start_date',
        'end_date',
        'is_locked',
        'locked_by',
        'locked_at',
        'lock_reason',
    ];

    protected $casts = [
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'locked_at' => 'datetime',
        'is_locked' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($period) {
            // Ensure year is set from start_date if not provided
            if (!$period->year && $period->start_date) {
                $period->year = $period->start_date->year;
            }
        });
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Check if a date falls within this period
     */
    public function containsDate(Carbon $date): bool
    {
        return $date->between($this->start_date, $this->end_date);
    }

    /**
     * Check if this period is locked
     */
    public function isLocked(): bool
    {
        return (bool) $this->is_locked;
    }

    /**
     * Lock the period
     */
    public function lock(?string $reason = null): bool
    {
        $this->update([
            'is_locked' => true,
            'locked_by' => auth()->id(),
            'locked_at' => now(),
            'lock_reason' => $reason ?? 'Period locked for year-end close',
        ]);

        return true;
    }

    /**
     * Unlock the period
     */
    public function unlock(): bool
    {
        $this->update([
            'is_locked' => false,
            'locked_by' => null,
            'locked_at' => null,
            'lock_reason' => null,
        ]);

        return true;
    }

    /**
     * Scope for locked periods
     */
    public function scopeLocked($query)
    {
        return $query->where('is_locked', true);
    }

    /**
     * Scope for unlocked periods
     */
    public function scopeUnlocked($query)
    {
        return $query->where('is_locked', false);
    }

    /**
     * Scope for periods containing a specific date
     */
    public function scopeContainingDate($query, Carbon $date)
    {
        return $query->where('start_date', '<=', $date)
                     ->where('end_date', '>=', $date);
    }

    /**
     * Scope for periods on or before a specific date
     */
    public function scopeBeforeDate($query, Carbon $date)
    {
        // Use date() function to compare just the date portion, ignoring time
        return $query->whereDate('end_date', '<=', $date);
    }

    /**
     * Create monthly periods for a year
     */
    public static function createMonthlyPeriodsForYear(int $year): array
    {
        $periods = [];

        for ($month = 1; $month <= 12; $month++) {
            $startDate = Carbon::create($year, $month, 1)->startOfMonth();
            $endDate = Carbon::create($year, $month, 1)->endOfMonth();

            $periods[] = self::create([
                'name' => $startDate->format('F Y'),
                'year' => $year,
                'period_type' => self::TYPE_MONTHLY,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }

        return $periods;
    }

    /**
     * Create quarterly periods for a year
     */
    public static function createQuarterlyPeriodsForYear(int $year): array
    {
        $periods = [];

        $quarters = [
            1 => ['Q1', 1, 3],
            2 => ['Q2', 4, 6],
            3 => ['Q3', 7, 9],
            4 => ['Q4', 10, 12],
        ];

        foreach ($quarters as $q => [$name, $startMonth, $endMonth]) {
            $startDate = Carbon::create($year, $startMonth, 1)->startOfMonth();
            $endDate = Carbon::create($year, $endMonth, 1)->endOfMonth();

            $periods[] = self::create([
                'name' => "{$name} {$year}",
                'year' => $year,
                'period_type' => self::TYPE_QUARTERLY,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }

        return $periods;
    }

    /**
     * Create annual period for a year
     */
    public static function createAnnualPeriodForYear(int $year): self
    {
        $startDate = Carbon::create($year, 1, 1)->startOfYear();
        $endDate = Carbon::create($year, 12, 31)->endOfYear();

        return self::create([
            'name' => "FY {$year}",
            'year' => $year,
            'period_type' => self::TYPE_ANNUAL,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }
}

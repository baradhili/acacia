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
     * Fiscal-year month for an offset (0 = first month of the FY): FY 2025
     * with a July start runs Jul 2025 – Jun 2026, so offset 6 is Jan 2026.
     */
    protected static function fiscalMonth(int $year, int $offset, int $startMonth): Carbon
    {
        $monthIndex = ($startMonth - 1) + $offset;

        return Carbon::create($year + intdiv($monthIndex, 12), $monthIndex % 12 + 1, 1);
    }

    /**
     * Create monthly periods for a fiscal year. $year is the FY label
     * (matching ifrs_reporting_periods.calendar_year): FY 2025 with the
     * default July start spans 1 Jul 2025 – 30 Jun 2026 across 12 monthly
     * rows.
     */
    public static function createMonthlyPeriodsForYear(int $year, int $startMonth = 7): array
    {
        $periods = [];

        for ($offset = 0; $offset < 12; $offset++) {
            $startDate = self::fiscalMonth($year, $offset, $startMonth);
            $endDate = $startDate->copy()->endOfMonth();

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
     * Create quarterly periods for a fiscal year (Q1 = first three months
     * of the FY, e.g. Jul–Sep for a July start).
     */
    public static function createQuarterlyPeriodsForYear(int $year, int $startMonth = 7): array
    {
        $periods = [];

        for ($q = 1; $q <= 4; $q++) {
            $startDate = self::fiscalMonth($year, ($q - 1) * 3, $startMonth);
            $endDate = self::fiscalMonth($year, ($q - 1) * 3 + 2, $startMonth)->endOfMonth();

            $periods[] = self::create([
                'name' => "Q{$q} FY {$year}",
                'year' => $year,
                'period_type' => self::TYPE_QUARTERLY,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
        }

        return $periods;
    }

    /**
     * Create the annual period for a fiscal year (FY 2025 = 1 Jul 2025 –
     * 30 Jun 2026 for a July start).
     */
    public static function createAnnualPeriodForYear(int $year, int $startMonth = 7): self
    {
        $startDate = Carbon::create($year, $startMonth, 1);
        $endDate = Carbon::create($year + 1, $startMonth, 1)->subDay()->endOfDay();

        return self::create([
            'name' => "FY {$year}",
            'year' => $year,
            'period_type' => self::TYPE_ANNUAL,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }
}

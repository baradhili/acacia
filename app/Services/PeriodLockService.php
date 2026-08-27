<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Support\Collection;

class PeriodLockService
{
    /**
     * Check if a date is within a locked period
     */
    public function isDateLocked(Carbon $date): bool
    {
        $period = FiscalPeriod::containingDate($date)->first();

        return $period && $period->isLocked();
    }

    /**
     * Whether ledger postings are blocked for a date: either the app's
     * FiscalPeriod covering it is locked, or the IFRS ReportingPeriod for
     * its financial year has been CLOSED (the year-end close sets this —
     * the package then rejects any new transaction in that year).
     *
     * Absent period rows mean open/unlocked, mirroring the firstOrCreate
     * convention used across the posting paths; this never creates rows.
     */
    public function isDateBlocked(Carbon $date, ?Entity $entity = null): bool
    {
        if ($this->isDateLocked($date)) {
            return true;
        }

        $entity ??= \App\Services\IfrsPosting::resolveEntity();
        if (!$entity) {
            return false;
        }

        $year = ReportingPeriod::year($date, $entity);
        $period = ReportingPeriod::withoutGlobalScope(\IFRS\Scopes\EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('calendar_year', $year)
            ->first();

        return $period && $period->status === ReportingPeriod::CLOSED;
    }

    /**
     * Friendly explanation for why a date is blocked (null when it isn't).
     */
    public function dateBlockedMessage(Carbon $date, ?Entity $entity = null): ?string
    {
        $locked = $this->getLockedPeriodForDate($date);
        if ($locked) {
            return "Period '{$locked->name}' is locked"
                . ($locked->lock_reason ? " ({$locked->lock_reason})" : '')
                . '. Contact an administrator to unlock.';
        }

        $entity ??= \App\Services\IfrsPosting::resolveEntity();
        if (!$entity) {
            return null;
        }

        $year = ReportingPeriod::year($date, $entity);
        $period = ReportingPeriod::withoutGlobalScope(\IFRS\Scopes\EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('calendar_year', $year)
            ->first();

        if ($period && $period->status === ReportingPeriod::CLOSED) {
            $end = ReportingPeriod::periodEnd($date, $entity)->format('d M Y');

            return "Financial year {$year} is closed (ended {$end}). "
                . 'Reopen the year from the Financial Years page or use a later date.';
        }

        return null;
    }

    /**
     * Get the locked period for a date (if any)
     */
    public function getLockedPeriodForDate(Carbon $date): ?FiscalPeriod
    {
        return FiscalPeriod::containingDate($date)
            ->locked()
            ->first();
    }

    /**
     * Lock all periods before a given date
     */
    public function lockPeriodsBeforeDate(Carbon $date, ?string $reason = null): array
    {
        $periods = FiscalPeriod::beforeDate($date)->unlocked()->get();
        
        $locked = 0;
        $alreadyLocked = 0;

        foreach ($periods as $period) {
            if ($period->isLocked()) {
                $alreadyLocked++;
            } else {
                $period->lock($reason);
                $locked++;
            }
        }

        return [
            'locked' => $locked,
            'already_locked' => $alreadyLocked,
            'total_periods' => $periods->count(),
        ];
    }

    /**
     * Lock a specific period
     */
    public function lockPeriod(FiscalPeriod $period, ?string $reason = null): bool
    {
        return $period->lock($reason);
    }

    /**
     * Unlock a specific period
     */
    public function unlockPeriod(FiscalPeriod $period): bool
    {
        return $period->unlock();
    }

    /**
     * Validate that transactions can be created/modified in a period
     */
    public function validateTransactionDate(Carbon $date): array
    {
        $period = FiscalPeriod::containingDate($date)->first();

        if (!$period) {
            return [
                'valid' => true,
                'message' => 'No period defined for this date',
            ];
        }

        if ($period->isLocked()) {
            return [
                'valid' => false,
                'message' => "Period '{$period->name}' is locked. Contact an administrator to unlock.",
                'period' => $period,
                'locked_by' => $period->lockedBy?->name,
                'locked_at' => $period->locked_at,
                'lock_reason' => $period->lock_reason,
            ];
        }

        return [
            'valid' => true,
            'message' => 'Transaction date is valid',
            'period' => $period,
        ];
    }

    /**
     * Get all locked periods
     */
    public function getLockedPeriods(): Collection
    {
        return FiscalPeriod::locked()
            ->orderBy('start_date', 'desc')
            ->get();
    }

    /**
     * Get all unlocked periods
     */
    public function getUnlockedPeriods(): Collection
    {
        return FiscalPeriod::unlocked()
            ->orderBy('start_date', 'desc')
            ->get();
    }

    /**
     * Get period status summary
     */
    public function getPeriodStatus(): array
    {
        $periods = FiscalPeriod::all();

        return [
            'total' => $periods->count(),
            'locked' => $periods->where('is_locked', true)->count(),
            'unlocked' => $periods->where('is_locked', false)->count(),
            'locked_periods' => $this->getLockedPeriods()->map(fn($p) => [
                'name' => $p->name,
                'locked_at' => $p->locked_at?->toIso8601String(),
                'locked_by' => $p->lockedBy?->name,
            ]),
        ];
    }

    /**
     * Create periods for a fiscal year
     */
    public function createPeriodsForYear(int $year, string $type = FiscalPeriod::TYPE_MONTHLY): array
    {
        return match ($type) {
            FiscalPeriod::TYPE_MONTHLY => FiscalPeriod::createMonthlyPeriodsForYear($year),
            FiscalPeriod::TYPE_QUARTERLY => FiscalPeriod::createQuarterlyPeriodsForYear($year),
            FiscalPeriod::TYPE_ANNUAL => [FiscalPeriod::createAnnualPeriodForYear($year)],
            default => throw new \InvalidArgumentException("Invalid period type: {$type}"),
        };
    }

    /**
     * Check if there are any transactions in a period
     */
    public function hasTransactionsInPeriod(FiscalPeriod $period): bool
    {
        $startDate = $period->start_date;
        $endDate = $period->end_date;

        $hasInvoices = Invoice::whereBetween('issue_date', [$startDate, $endDate])->exists();
        $hasPayments = Payment::whereBetween('payment_date', [$startDate, $endDate])->exists();

        return $hasInvoices || $hasPayments;
    }
}

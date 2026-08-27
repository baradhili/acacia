<?php

namespace App\Services;

use App\Models\BillPayment;
use App\Models\FiscalYearClose;
use App\Models\Payment;
use App\Models\Prepayment;
use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Scopes\EntityScope;

/**
 * Financial year end/start handling.
 *
 * A close works in stages: a read-only trial close (checklist + proposed
 * closing entries), an approval hand-off (accountant/admin, requester ≠
 * approver), then execution — closing JournalEntries transfer every P&L
 * account's cumulative balance to Retained Earnings, the IFRS
 * ReportingPeriod is marked CLOSED (the package then rejects any new
 * transaction in that year) and the app's FiscalPeriods are locked.
 *
 * Closing entries carry the reference FY-CLOSE-{year}; reports exclude
 * that prefix from P&L movement so historical statements survive the
 * close. A reopen mirrors the entries back out and reopens the period.
 */
class FiscalYearService
{
    /**
     * First and last instant of the financial year $year (FY label,
     * matching ifrs_reporting_periods.calendar_year — FY 2025 = 1 Jul
     * 2025 – 30 Jun 2026 for a July start). The anchor must be a date
     * inside the FY itself: the package maps pre-year_start months to
     * the previous FY.
     */
    public function bounds(Entity $entity, int $year): array
    {
        $anchor = Carbon::create($year, $entity->year_start, 1);

        return [
            'start' => ReportingPeriod::periodStart($anchor, $entity),
            'end' => ReportingPeriod::periodEnd($anchor, $entity),
        ];
    }

    public function currentYear(Entity $entity): int
    {
        return ReportingPeriod::year(now(), $entity);
    }

    /**
     * The IFRS ReportingPeriod row for $year, created OPEN when absent
     * (same convention as IfrsPosting::ensureReportingPeriod()).
     */
    public function reportingPeriod(Entity $entity, int $year): ReportingPeriod
    {
        return ReportingPeriod::withoutGlobalScope(EntityScope::class)->firstOrCreate(
            ['entity_id' => $entity->id, 'calendar_year' => $year],
            ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
        );
    }

    public function isClosed(Entity $entity, int $year): bool
    {
        $period = ReportingPeriod::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('calendar_year', $year)
            ->first();

        return $period !== null && $period->status === ReportingPeriod::CLOSED;
    }

    /**
     * The latest year whose end date has passed but which is not closed —
     * null when nothing needs attention (the warning-banner case). Years
     * without a ReportingPeriod row never had postings, so they don't
     * need closing.
     */
    public function unclosedPriorYear(Entity $entity): ?int
    {
        $current = $this->currentYear($entity);

        for ($year = $current - 1; $year >= $current - 10; $year--) {
            $exists = ReportingPeriod::withoutGlobalScope(EntityScope::class)
                ->where('entity_id', $entity->id)
                ->where('calendar_year', $year)
                ->exists();
            if (!$exists) {
                return null;
            }

            if (!$this->isClosed($entity, $year)) {
                return $year;
            }
        }

        return null;
    }

    public function closeRecord(Entity $entity, int $year): ?FiscalYearClose
    {
        return FiscalYearClose::where('entity_id', $entity->id)->where('year', $year)->first();
    }

    /**
     * The workflow row for $year, created in trial status when absent.
     */
    public function ensureCloseRecord(Entity $entity, int $year): FiscalYearClose
    {
        return FiscalYearClose::firstOrCreate(
            ['entity_id' => $entity->id, 'year' => $year],
            ['status' => FiscalYearClose::STATUS_TRIAL],
        );
    }

    /**
     * Guard shared by trial/approve/close: only fully-ended financial
     * years can be closed, and a closed year must be reopened first.
     */
    public function assertClosable(Entity $entity, int $year): void
    {
        if ($year >= $this->currentYear($entity)) {
            throw new \InvalidArgumentException(
                "FY {$year} has not ended yet (current FY is {$this->currentYear($entity)}); only ended years can be closed."
            );
        }

        if ($this->isClosed($entity, $year)) {
            throw new \InvalidArgumentException(
                "FY {$year} is already closed. Reopen it before running another close."
            );
        }
    }

    /**
     * Pre-close checklist: blocking items are conditions that would make
     * the closing entries wrong (ledger activity missing from the year);
     * informational items are worth reviewing but do not stop a close.
     */
    public function checklist(Entity $entity, int $year): array
    {
        ['start' => $start, 'end' => $end] = $this->bounds($entity, $year);

        $unpostedPayments = Payment::whereNull('ifrs_receipt_id')
            ->where('status', '!=', Payment::STATUS_VOID)
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('payment_date')
            ->get(['payment_number', 'payment_date', 'amount']);
        $unpostedBillPayments = BillPayment::whereNull('ifrs_payment_id')
            ->where('status', '!=', BillPayment::STATUS_VOID)
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('payment_date')
            ->get(['payment_number', 'payment_date', 'amount']);
        $overdueAmortisations = Prepayment::where('status', Prepayment::STATUS_ACTIVE)
            ->whereDate('next_period_date', '<=', $end->toDateString())
            ->orderBy('next_period_date')
            ->get(['description', 'next_period_date', 'monthly_amount']);
        $unreconciledBank = \App\Models\BankTransaction::where('status', 'PENDING')
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        $postedTransactions = Transaction::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereBetween('transaction_date', [$start, $end->copy()->endOfDay()])
            ->count();

        $formatList = fn ($items) => $items->take(5)->map(
            fn ($i) => $i->payment_number ?? $i->description
        )->implode(', ') . ($items->count() > 5 ? ' …' : '');

        return [
            [
                'key' => 'unposted_payments',
                'label' => 'All client payments posted to the ledger',
                'blocking' => true,
                'pass' => $unpostedPayments->isEmpty(),
                'detail' => $unpostedPayments->isEmpty()
                    ? 'No unposted payments dated in the year.'
                    : $unpostedPayments->count() . ' unposted: ' . $formatList($unpostedPayments),
            ],
            [
                'key' => 'unposted_bill_payments',
                'label' => 'All supplier payments posted to the ledger',
                'blocking' => true,
                'pass' => $unpostedBillPayments->isEmpty(),
                'detail' => $unpostedBillPayments->isEmpty()
                    ? 'No unposted supplier payments dated in the year.'
                    : $unpostedBillPayments->count() . ' unposted: ' . $formatList($unpostedBillPayments),
            ],
            [
                'key' => 'overdue_amortisation',
                'label' => 'Prepayment amortisation posted through year end',
                'blocking' => true,
                'pass' => $overdueAmortisations->isEmpty(),
                'detail' => $overdueAmortisations->isEmpty()
                    ? 'All amortisation entries due by year end are posted.'
                    : $overdueAmortisations->count() . ' schedules behind: ' . $formatList($overdueAmortisations),
            ],
            [
                'key' => 'unreconciled_bank_transactions',
                'label' => 'Bank transactions reconciled',
                'blocking' => false,
                'pass' => $unreconciledBank === 0,
                'detail' => $unreconciledBank === 0
                    ? 'No pending bank transactions dated in the year.'
                    : $unreconciledBank . ' pending bank transaction(s) dated in the year.',
            ],
            [
                'key' => 'ledger_activity',
                'label' => 'Ledger activity in the year',
                'blocking' => false,
                'pass' => true,
                'detail' => $postedTransactions . ' posted ledger transaction(s) dated in the year.',
            ],
        ];
    }

    public function checklistPasses(array $checklist): bool
    {
        foreach ($checklist as $item) {
            if ($item['blocking'] && !$item['pass']) {
                return false;
            }
        }

        return true;
    }
}

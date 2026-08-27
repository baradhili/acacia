<?php

namespace App\Services;

use App\Models\BillPayment;
use App\Models\FiscalYearClose;
use App\Models\Payment;
use App\Models\Prepayment;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
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

    /**
     * P&L account types closed out to Retained Earnings at year end.
     */
    public const PNL_ACCOUNT_TYPES = [
        Account::OPERATING_REVENUE,
        Account::NON_OPERATING_REVENUE,
        Account::DIRECT_EXPENSE,
        Account::OPERATING_EXPENSE,
        Account::OVERHEAD_EXPENSE,
        Account::OTHER_EXPENSE,
    ];

    /**
     * The equity account closing entries post to (seeded code 3200).
     */
    public function retainedEarningsAccount(Entity $entity): ?Account
    {
        return Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('account_type', Account::EQUITY)
            ->where('code', '3200')
            ->first();
    }

    /**
     * Trial close for a ended financial year — pure computation, no
     * ledger writes. Produces the checklist plus the proposed closing
     * entries: every P&L account's cumulative balance as at year end
     * (epoch-to-date ledger movement plus opening Balance rows — the
     * same convention the financial statements use), split into this
     * year's movement and the carry-in from years never closed before.
     *
     * Closing to cumulative (not just the year's movement) makes the
     * first-ever close a catch-up that moves all historic profit into
     * Retained Earnings; once every prior year has been closed the
     * carry-in is zero and the two conventions coincide.
     *
     * Balances are debit-positive: revenue accounts are negative, so a
     * profitable year yields a positive net_to_retained_earnings.
     */
    public function trialClose(Entity $entity, int $year): array
    {
        $this->assertClosable($entity, $year);

        ['start' => $start, 'end' => $end] = $this->bounds($entity, $year);
        $endOfDay = $end->copy()->endOfDay();
        $epoch = Carbon::create(2000, 1, 1);
        $checklist = $this->checklist($entity, $year);

        $re = $this->retainedEarningsAccount($entity);
        if (!$re) {
            throw new \RuntimeException(
                'Retained Earnings account (code 3200) not found for entity — required by the year-end close.'
            );
        }

        $lines = [];
        $sumTotal = 0.0;
        $sumMovement = 0.0;
        $sumPrior = 0.0;

        $accounts = Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereIn('account_type', self::PNL_ACCOUNT_TYPES)
            ->orderBy('code')
            ->get();

        foreach ($accounts as $account) {
            // Cumulative as-at balance, opening Balance rows included —
            // closing entries from earlier year-ends already net out of
            // this figure, which is what makes repeated closes correct.
            $total = (float) Ledger::balance($account, $epoch, $endOfDay, $entity->currency_id)[$entity->currency_id]
                + OpeningBalances::effectiveOpening($account, $entity);

            if (abs($total) < 0.005) {
                continue;
            }

            $movement = (float) Ledger::balance($account, $start, $endOfDay, $entity->currency_id)[$entity->currency_id];
            $prior = round($total - $movement, 2);

            $lines[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->account_type,
                'balance' => round($total, 2),
                'fy_movement' => round($movement, 2),
                'prior_years' => $prior,
                // A credit balance (negative) is closed by debiting the
                // account; a debit balance by crediting it.
                'close_side' => $total < 0 ? 'debit' : 'credit',
                'amount' => round(abs($total), 2),
            ];

            $sumTotal += round($total, 2);
            $sumMovement += round($movement, 2);
            $sumPrior += $prior;
        }

        return [
            'year' => $year,
            'start' => $start,
            'end' => $end,
            'checklist' => $checklist,
            'checklist_passes' => $this->checklistPasses($checklist),
            'lines' => $lines,
            // P&L credit balances are profit: negate the debit-positive sums.
            'fy_net_profit' => round(-$sumMovement, 2),
            'prior_years_catch_up' => round(-$sumPrior, 2),
            'net_to_retained_earnings' => round(-$sumTotal, 2),
            'retained_earnings' => ['id' => $re->id, 'code' => $re->code, 'name' => $re->name],
        ];
    }

    /**
     * Persist a trial close onto the workflow row (creating it in trial
     * status when absent). Ledger untouched; an existing approval
     * request keeps its status — the refreshed snapshot is what the
     * approver reviews.
     */
    public function storeTrial(Entity $entity, int $year, ?int $userId = null): array
    {
        $trial = $this->trialClose($entity, $year);
        $record = $this->ensureCloseRecord($entity, $year);

        $record->fill([
            'checklist' => $trial['checklist'],
            'trial_totals' => [
                'fy_net_profit' => $trial['fy_net_profit'],
                'prior_years_catch_up' => $trial['prior_years_catch_up'],
                'net_to_retained_earnings' => $trial['net_to_retained_earnings'],
                'line_count' => count($trial['lines']),
            ],
        ]);
        if ($userId && !$record->requested_by) {
            $record->requested_by = $userId;
        }
        $record->save();

        $trial['record'] = $record;

        return $trial;
    }
}

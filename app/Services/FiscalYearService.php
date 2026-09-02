<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\BillPayment;
use App\Models\EntitySetting;
use App\Models\FiscalPeriod;
use App\Models\FiscalYearClose;
use App\Models\Payment;
use App\Models\Prepayment;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Models\Transaction;
use IFRS\Scopes\EntityScope;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * How far back an admin may pin the open year: the pin may be at most
     * this many financial years before the calendar-derived one.
     */
    public const OPEN_YEAR_MAX_AGE = 7;

    /**
     * The financial year "now" falls in, ignoring any admin pin.
     */
    public function clockYear(Entity $entity): int
    {
        return ReportingPeriod::year(now(), $entity);
    }

    /**
     * The working financial year: the admin's open-year pin when one is
     * set and still within the allowed window, otherwise the calendar
     * year. Drives the Financial Years page anchor, the unclosed-prior-year
     * warning and the year-end closability rules.
     */
    public function currentYear(Entity $entity): int
    {
        $clock = $this->clockYear($entity);
        $pinned = EntitySetting::storedOpenYear($entity);

        // A pin left behind by the clock (outside the window) has expired;
        // fall back to the calendar year rather than trust a stale value.
        if ($pinned !== null && $pinned >= $clock - self::OPEN_YEAR_MAX_AGE && $pinned <= $clock) {
            return $pinned;
        }

        return $clock;
    }

    /**
     * The [min, max] financial years the open year may be pinned to.
     */
    public function openYearWindow(Entity $entity): array
    {
        $clock = $this->clockYear($entity);

        return [$clock - self::OPEN_YEAR_MAX_AGE, $clock];
    }

    /**
     * Pin (or clear) the open year. Pinning is the gateway for backfilling
     * a past year: it ensures the year's IFRS ReportingPeriod row exists
     * (OPEN), which makes the year selectable for opening balances and
     * postable for transactions dated in it. Closed years must be reopened
     * first.
     */
    public function setOpenYear(Entity $entity, ?int $year): void
    {
        if ($year === null) {
            EntitySetting::setOpenYear($entity, null);

            return;
        }

        [$min, $max] = $this->openYearWindow($entity);
        if ($year < $min || $year > $max) {
            throw new \InvalidArgumentException(
                "FY {$year} is outside the allowed range FY {$min} – FY {$max}."
            );
        }

        if ($this->isClosed($entity, $year)) {
            throw new \InvalidArgumentException(
                "FY {$year} is closed. Reopen it from the Financial Years page before setting it as the open year."
            );
        }

        EntitySetting::setOpenYear($entity, $year);
        $this->reportingPeriod($entity, $year);
    }

    /**
     * Years the open year may be pinned to — the calendar FY and the seven
     * before it — with their bounds and closed flags, newest first.
     */
    public function openYearOptions(Entity $entity): array
    {
        [$min, $max] = $this->openYearWindow($entity);

        $options = [];
        for ($year = $max; $year >= $min; $year--) {
            $bounds = $this->bounds($entity, $year);
            $options[] = (object) [
                'year' => $year,
                'start' => $bounds['start'],
                'end' => $bounds['end'],
                'closed' => $this->isClosed($entity, $year),
            ];
        }

        return $options;
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
            if (! $exists) {
                return null;
            }

            if (! $this->isClosed($entity, $year)) {
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
        $unreconciledBank = BankTransaction::where('status', 'PENDING')
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        $postedTransactions = Transaction::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereBetween('transaction_date', [$start, $end->copy()->endOfDay()])
            ->count();

        $formatList = fn ($items) => $items->take(5)->map(
            fn ($i) => $i->payment_number ?? $i->description
        )->implode(', ').($items->count() > 5 ? ' …' : '');

        return [
            [
                'key' => 'unposted_payments',
                'label' => 'All client payments posted to the ledger',
                'blocking' => true,
                'pass' => $unpostedPayments->isEmpty(),
                'detail' => $unpostedPayments->isEmpty()
                    ? 'No unposted payments dated in the year.'
                    : $unpostedPayments->count().' unposted: '.$formatList($unpostedPayments),
            ],
            [
                'key' => 'unposted_bill_payments',
                'label' => 'All supplier payments posted to the ledger',
                'blocking' => true,
                'pass' => $unpostedBillPayments->isEmpty(),
                'detail' => $unpostedBillPayments->isEmpty()
                    ? 'No unposted supplier payments dated in the year.'
                    : $unpostedBillPayments->count().' unposted: '.$formatList($unpostedBillPayments),
            ],
            [
                'key' => 'overdue_amortisation',
                'label' => 'Prepayment amortisation posted through year end',
                'blocking' => true,
                'pass' => $overdueAmortisations->isEmpty(),
                'detail' => $overdueAmortisations->isEmpty()
                    ? 'All amortisation entries due by year end are posted.'
                    : $overdueAmortisations->count().' schedules behind: '.$formatList($overdueAmortisations),
            ],
            [
                'key' => 'unreconciled_bank_transactions',
                'label' => 'Bank transactions reconciled',
                'blocking' => false,
                'pass' => $unreconciledBank === 0,
                'detail' => $unreconciledBank === 0
                    ? 'No pending bank transactions dated in the year.'
                    : $unreconciledBank.' pending bank transaction(s) dated in the year.',
            ],
            [
                'key' => 'ledger_activity',
                'label' => 'Ledger activity in the year',
                'blocking' => false,
                'pass' => true,
                'detail' => $postedTransactions.' posted ledger transaction(s) dated in the year.',
            ],
        ];
    }

    public function checklistPasses(array $checklist): bool
    {
        foreach ($checklist as $item) {
            if ($item['blocking'] && ! $item['pass']) {
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
     * Add the "exclude year-end closing entries" clause to a query
     * already joined to ifrs_transactions as t. NULL references (most
     * transactions) must survive: SQL NOT over a NULL LIKE evaluates to
     * NULL, which drops the row from the WHERE entirely.
     */
    public static function excludeClosingEntries($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('t.reference')
                ->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%');
        });
    }

    /**
     * Ledger movement for $account between $start and $end (debit-
     * positive, same convention as Ledger::balance()) excluding the
     * year-end closing entries — transactions whose reference starts
     * with FY-CLOSE-, including their -REV reversals. This is what lets
     * P&L statements for a closed year keep reporting the year's real
     * trading results instead of the zeroed post-close balances.
     *
     * Raw table query because reference lives on the transaction, which
     * Ledger::balance() cannot filter by.
     */
    public static function movementExcludingClosures(Account $account, Carbon $start, Carbon $end, Entity $entity): float
    {
        return round((float) self::excludeClosingEntries(
            DB::table('ifrs_ledgers as l')
                ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
                ->where('l.post_account', $account->id)
                ->whereBetween('l.posting_date', [$start, $end])
                ->where('l.currency_id', $entity->currency_id)
                ->whereNull('l.deleted_at')
                ->whereNull('t.deleted_at')
        )
            ->selectRaw("COALESCE(SUM(CASE WHEN l.entry_type = 'D' THEN l.amount / l.rate ELSE -l.amount / l.rate END), 0) as movement")
            ->value('movement'), 4);
    }

    /**
     * Net profit over [$start, $end] from P&L movement excluding closing
     * entries (see movementExcludingClosures()). The balance sheet uses
     * this for its on-the-fly equity figure.
     */
    public function netProfitExcludingClosures(Entity $entity, Carbon $start, Carbon $end): float
    {
        $movements = self::excludeClosingEntries(
            DB::table('ifrs_ledgers as l')
                ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
                ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
                ->where('a.entity_id', $entity->id)
                ->whereIn('a.account_type', self::PNL_ACCOUNT_TYPES)
                ->where('l.currency_id', $entity->currency_id)
                ->whereBetween('l.posting_date', [$start, $end])
                ->whereNull('l.deleted_at')
                ->whereNull('t.deleted_at')
        )
            ->groupBy('a.account_type')
            ->selectRaw("a.account_type,
                COALESCE(SUM(CASE WHEN l.entry_type = 'D' THEN l.amount / l.rate ELSE -l.amount / l.rate END), 0) as movement")
            ->pluck('movement', 'a.account_type');

        // Revenue movements are credit-negative: negate them, then net
        // off the (debit-positive) expense movements.
        $revenue = (float) ($movements[Account::OPERATING_REVENUE] ?? 0)
            + (float) ($movements[Account::NON_OPERATING_REVENUE] ?? 0);
        $expenses = (float) ($movements[Account::DIRECT_EXPENSE] ?? 0)
            + (float) ($movements[Account::OPERATING_EXPENSE] ?? 0)
            + (float) ($movements[Account::OVERHEAD_EXPENSE] ?? 0)
            + (float) ($movements[Account::OTHER_EXPENSE] ?? 0);

        return round(-$revenue - $expenses, 2);
    }

    /**
     * Trial close for a ended financial year — pure computation, no
     * ledger writes. Produces the checklist plus the proposed closing
     * entries: every P&L account's cumulative balance as at year end
     * (the opening snapshot in force plus ledger movement after it —
     * the same convention the financial statements use), split into
     * this year's movement and the carry-in from years never closed
     * before.
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
        $checklist = $this->checklist($entity, $year);

        $re = $this->retainedEarningsAccount($entity);
        if (! $re) {
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
            // Cumulative as-at balance via the opening snapshot in force
            // — closing entries from earlier year-ends already net out of
            // this figure, which is what makes repeated closes correct.
            $total = OpeningBalances::balanceAt($account, $entity, $endOfDay);

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
        if ($userId && ! $record->requested_by) {
            $record->requested_by = $userId;
        }
        $record->save();

        $trial['record'] = $record;

        return $trial;
    }

    /**
     * Request approval for the stored trial close. The snapshot is
     * refreshed first so the approver always reviews current numbers.
     */
    public function submit(Entity $entity, int $year, ?int $userId = null): FiscalYearClose
    {
        $this->assertClosable($entity, $year);
        $record = $this->ensureCloseRecord($entity, $year);

        if (! $record->canSubmit()) {
            throw new \InvalidArgumentException(
                "FY {$year} cannot be submitted for approval from status '{$record->status}'."
            );
        }

        $this->storeTrial($entity, $year, $userId);

        $record->status = FiscalYearClose::STATUS_PENDING_APPROVAL;
        if ($userId) {
            $record->requested_by = $userId;
        }
        $record->save();

        return $record;
    }

    /**
     * Approve a pending close request. The approver must hold the
     * accountant or admin role and — unless forced — must not be the
     * requester: the four-eyes hand-off is the point of the workflow.
     */
    public function approve(Entity $entity, int $year, User $approver, bool $force = false): FiscalYearClose
    {
        $record = $this->closeRecord($entity, $year);

        if (! $record || ! $record->canApprove()) {
            throw new \InvalidArgumentException("FY {$year} has no close request pending approval.");
        }

        if (! $approver->hasAnyRole('admin', 'accountant')) {
            throw new \InvalidArgumentException(
                'Only an accountant or an admin can approve a financial year close.'
            );
        }

        if (! $force && $record->requested_by === $approver->id) {
            throw new \InvalidArgumentException(
                "The requester cannot approve their own FY {$year} close request."
            );
        }

        $record->fill([
            'status' => FiscalYearClose::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        return $record;
    }

    /**
     * Execute the year-end close: post the closing JournalEntries that
     * transfer every P&L balance to Retained Earnings, mark the IFRS
     * ReportingPeriod CLOSED (the package then rejects any new posting
     * dated in the year), lock the app's FiscalPeriods for the year and
     * snapshot everything on the workflow row. Requires an approved row —
     * or $force, the documented CLI escape hatch that also skips failed
     * blocking checklist items.
     */
    public function close(Entity $entity, int $year, bool $force = false): FiscalYearClose
    {
        $this->assertClosable($entity, $year);

        $record = $this->closeRecord($entity, $year);
        if ((! $record || ! $record->canClose()) && ! $force) {
            throw new \InvalidArgumentException(
                "FY {$year} has no approved close request. Submit and approve it first "
                .'(or pass --force to bypass the workflow).'
            );
        }
        $record ??= $this->ensureCloseRecord($entity, $year);

        return DB::transaction(function () use ($entity, $year, $force, $record) {
            $trial = $this->trialClose($entity, $year);

            if (! $trial['checklist_passes'] && ! $force) {
                $failed = collect($trial['checklist'])
                    ->filter(fn ($item) => $item['blocking'] && ! $item['pass'])
                    ->map(fn ($item) => $item['label'])
                    ->implode('; ');

                throw new \InvalidArgumentException(
                    "FY {$year} cannot be closed — blocking checklist items failed: {$failed}."
                );
            }

            ['end' => $end] = $this->bounds($entity, $year);
            $re = Account::withoutGlobalScope(EntityScope::class)->find($trial['retained_earnings']['id']);

            $transactionIds = [];
            foreach ([true, false] as $credited) {
                // JE with RE credited debits the P&L accounts holding
                // credit balances (revenue); RE debited credits those
                // holding debit balances (expenses). Routing by balance
                // sign keeps every line amount positive whatever the
                // account type. Line items take the side opposite the
                // main account — the package convention.
                $side = $credited ? 'debit' : 'credit';
                $lines = array_values(array_filter(
                    $trial['lines'],
                    fn ($line) => $line['close_side'] === $side
                ));

                if ($lines === []) {
                    continue;
                }

                $je = new JournalEntry([
                    'transaction_date' => IfrsPosting::transactionDate($end, $entity),
                    'account_id' => $re->id,
                    'credited' => $credited,
                    'entity_id' => $entity->id,
                    'narration' => "FY {$year} year-end close: "
                        .($credited ? 'revenue' : 'expense')
                        .' accounts closed to Retained Earnings',
                    'reference' => $record->closingReference(),
                ]);

                foreach ($lines as $line) {
                    // Persisted before addLineItem() — unsaved items share
                    // a null id and the package silently drops all but the
                    // first (same constraint as the posting paths).
                    $je->addLineItem(LineItem::create([
                        'account_id' => $line['account_id'],
                        'amount' => $line['amount'],
                        'quantity' => 1,
                        'vat_inclusive' => true,
                        'entity_id' => $entity->id,
                    ]));
                }

                $je->post();
                $transactionIds[] = $je->id;
            }

            // The closing entries are in; seal the period. The package
            // now rejects any further transaction dated in the year.
            $period = $this->reportingPeriod($entity, $year);
            $period->status = ReportingPeriod::CLOSED;
            $period->save();

            // Next FY must exist OPEN so day-1 postings of the new year
            // don't hit MissingReportingPeriod.
            $this->reportingPeriod($entity, $year + 1);

            // App-level lock: create the FY's monthly periods when absent
            // (idempotent across reopen/re-close cycles) then lock them.
            if (FiscalPeriod::where('year', $year)->doesntExist()) {
                FiscalPeriod::createMonthlyPeriodsForYear($year, $entity->year_start);
            }
            foreach (FiscalPeriod::where('year', $year)->get() as $fiscalPeriod) {
                if (! $fiscalPeriod->isLocked()) {
                    $fiscalPeriod->lock("FY {$year} closed");
                }
            }

            // The closed position becomes next year's opening set: one
            // Balance row per balance-sheet account, dated the year end
            // (the eve of FY {year+1}), carrying the full closing balance
            // including the retained profit just closed. Reports read it
            // as the superseding snapshot — no manual re-entry, ever.
            $this->createNextYearOpeningBalances($entity, $year, $end);

            $record->fill([
                'status' => FiscalYearClose::STATUS_CLOSED,
                'closed_at' => now(),
                'closing_transaction_ids' => $transactionIds,
                'checklist' => $trial['checklist'],
                'trial_totals' => [
                    'fy_net_profit' => $trial['fy_net_profit'],
                    'prior_years_catch_up' => $trial['prior_years_catch_up'],
                    'net_to_retained_earnings' => $trial['net_to_retained_earnings'],
                    'line_count' => count($trial['lines']),
                ],
            ])->save();

            Log::info('Financial year closed', [
                'entity_id' => $entity->id,
                'year' => $year,
                'closing_transaction_ids' => $transactionIds,
                'net_to_retained_earnings' => $trial['net_to_retained_earnings'],
                'forced' => $force,
            ]);

            return $record;
        });
    }

    /**
     * Mirror a closed year back open: reverse both closing entries
     * (reference FY-CLOSE-{year}-REV), reopen the IFRS ReportingPeriod
     * and unlock the app's FiscalPeriods. The closing entries and their
     * reversals both stay in the ledger (net zero) and both are excluded
     * from report movement by the FY-CLOSE- prefix.
     */
    public function reopen(Entity $entity, int $year): FiscalYearClose
    {
        $record = $this->closeRecord($entity, $year);

        if (! $record || ! $record->canReopen()) {
            throw new \InvalidArgumentException("FY {$year} has no executed close to reopen.");
        }

        if (! $this->isClosed($entity, $year)) {
            throw new \InvalidArgumentException(
                "FY {$year}'s reporting period is not CLOSED — nothing to reopen."
            );
        }

        return DB::transaction(function () use ($entity, $year, $record) {
            // Reopen the period first: the mirrored reversals keep the
            // original closing-entry dates, and posting into a CLOSED
            // period is what the package throws ClosedReportingPeriod for.
            $period = $this->reportingPeriod($entity, $year);
            $period->status = ReportingPeriod::OPEN;
            $period->save();

            foreach ($record->closing_transaction_ids ?? [] as $transactionId) {
                IfrsPosting::reverseTransaction(
                    (int) $transactionId,
                    "FY {$year} reopened: reversal of year-end closing entry",
                    $record->closingReference().'-REV',
                    throw: true,
                );
            }

            foreach (FiscalPeriod::where('year', $year)->locked()->get() as $fiscalPeriod) {
                $fiscalPeriod->unlock();
            }

            // The generated opening set for FY {year+1} was derived from
            // the now-reversed closing position — drop it; a later
            // re-close regenerates it from the fresh position.
            Balance::withoutGlobalScope(EntityScope::class)
                ->where('entity_id', $entity->id)
                ->where('reference', $record->closingReference().'-OB')
                ->delete();

            $record->fill([
                'status' => FiscalYearClose::STATUS_REOPENED,
                'reopened_at' => now(),
            ])->save();

            Log::info('Financial year reopened', [
                'entity_id' => $entity->id,
                'year' => $year,
                'closing_transaction_ids' => $record->closing_transaction_ids,
            ]);

            return $record;
        });
    }

    /**
     * Write FY {year+1}'s opening set from the closing position: one
     * Balance row per balance-sheet account (non-P&L) whose closing
     * balance is non-zero, dated the year end, stamped with the closing
     * reference plus "-OB" so the set is recognisable as system-
     * generated (read-only in the Opening Balances UI, removed by
     * reopen()). Any existing rows for the period are superseded first —
     * including a manual migration set, which the closing position
     * already contains. Runs inside close()'s transaction.
     */
    protected function createNextYearOpeningBalances(Entity $entity, int $year, Carbon $end): void
    {
        $nextPeriod = $this->reportingPeriod($entity, $year + 1);

        Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('reporting_period_id', $nextPeriod->id)
            ->delete();

        $accounts = Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->whereNotIn('account_type', self::PNL_ACCOUNT_TYPES)
            ->orderBy('code')
            ->get();

        $reference = FiscalYearClose::CLOSING_REFERENCE_PREFIX.$year.'-OB';

        foreach ($accounts as $account) {
            $balance = round(OpeningBalances::balanceAt($account, $entity, $end->copy()->endOfDay()), 2);
            if (abs($balance) < 0.01) {
                continue;
            }

            (new Balance([
                'entity_id' => $entity->id,
                'account_id' => $account->id,
                'reporting_period_id' => $nextPeriod->id,
                'currency_id' => $account->currency_id,
                'transaction_type' => Transaction::JN,
                'transaction_date' => $end->copy()->startOfDay(),
                'balance_type' => $balance > 0 ? Balance::DEBIT : Balance::CREDIT,
                'balance' => abs($balance),
                'reference' => $reference,
            ]))->save();
        }
    }
}

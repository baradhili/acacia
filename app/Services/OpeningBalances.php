<?php

namespace App\Services;

use App\Models\FiscalYearClose;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Scopes\EntityScope;

/**
 * Opening balances per the IFRS package's documented mechanism: Balance
 * rows on balance-sheet accounts, dated before their reporting period's
 * start. The package's own reports consume them via
 * Account::openingBalance() (closing = opening + period movement); the
 * app's cumulative ledger reports add them via effectiveOpening().
 *
 * Snapshots: an "opening set" is all Balance rows dated the same day
 * (the eve of the financial year they open) — an entity-level opening
 * trial balance, not a per-account patch-up. Reports use the LATEST set
 * dated on or before their as-of date plus ledger movement after that
 * day — balanceAt(); accounts absent from that set open at zero (their
 * pre-set history is superseded with everyone else's). A later set
 * supersedes an earlier one (the year-end close generates each new set
 * from the closing position, which already contains the migrated
 * amounts plus all history), so multiple sets can never double-count,
 * and ledger activity predating a migration set is ignored rather than
 * counted on top of it.
 */
class OpeningBalances
{
    /**
     * The opening snapshot in force for the entity at $asOf (default
     * now): the latest transaction_date across ALL of the entity's
     * Balance rows dated on or before $asOf — the date of the newest
     * opening set. Null when the entity has no rows by then.
     */
    public static function openingSnapshotDate(Entity $entity, ?Carbon $asOf = null): ?Carbon
    {
        $date = self::entityBalances($entity)
            ->when($asOf, fn ($query, $cut) => $query->whereDate('transaction_date', '<=', $cut->toDateString()))
            ->max('transaction_date');

        return $date ? Carbon::parse($date) : null;
    }

    /**
     * Signed (debit-positive) opening contribution of $account from the
     * snapshot in force at $asOf (default now): the sum of its Balance
     * rows at openingSnapshotDate() — credits land negative. An account
     * with no row on that date opens at zero; rows from superseded sets
     * are excluded.
     */
    public static function effectiveOpening(Account $account, Entity $entity, ?Carbon $asOf = null): float
    {
        $snapshotDate = self::openingSnapshotDate($entity, $asOf);
        if ($snapshotDate === null) {
            return 0.0;
        }

        $balances = self::entityBalances($entity)
            ->where('account_id', $account->id)
            ->whereDate('transaction_date', $snapshotDate->toDateString())
            ->get(['balance_type', 'balance']);

        return round((float) $balances->sum(
            fn ($balance) => $balance->balance_type === Balance::CREDIT ? -$balance->balance : $balance->balance
        ), 2);
    }

    /**
     * Signed (debit-positive) position of $account as at end of $asOf's
     * day: the entity's opening snapshot in force at that date (zero
     * contribution when the account is absent from the set) plus ledger
     * movement AFTER the snapshot's day (the whole ledger from an
     * arbitrary epoch when no snapshot exists). Exact as-at balances on
     * a single snapshot basis — never mixing per-account sets, never
     * double-counting a superseded set or activity predating a set.
     */
    public static function balanceAt(Account $account, Entity $entity, Carbon $asOf): float
    {
        $asOf = $asOf->copy()->endOfDay();

        $balance = (float) Ledger::balance(
            $account,
            ($snapshotDate = self::openingSnapshotDate($entity, $asOf))
                ? $snapshotDate->copy()->addDay()->startOfDay()
                : Carbon::create(2000, 1, 1),
            $asOf,
            $entity->currency_id
        )[$entity->currency_id];

        if ($snapshotDate !== null) {
            $balance += self::effectiveOpening($account, $entity, $asOf);
        }

        return $balance;
    }

    /**
     * Fiscal years an opening balance can be entered for: the entity's
     * periods up to and including the current FY. Later years are excluded
     * because Balance::save() rejects transaction dates on or after the
     * current reporting period's start.
     */
    public static function editablePeriods(Entity $entity)
    {
        return self::entityPeriods($entity)
            ->where('calendar_year', '<=', ReportingPeriod::year(now(), $entity))
            ->orderByDesc('calendar_year')
            ->get();
    }

    /**
     * The date an opening balance for $period carries: the day before the
     * period starts (e.g. 30 June for a July year start) — Balance::save()
     * requires the date to fall before the reporting period's start.
     */
    public static function periodOpeningDate(ReportingPeriod $period, Entity $entity): Carbon
    {
        return Carbon::create($period->calendar_year, $entity->year_start, 1)->subDay();
    }

    /**
     * Whether the period's opening set was generated by a year-end close
     * rather than entered by hand. True when the set carries the
     * FY-CLOSE-{year}-OB reference, or when the preceding financial year
     * has an executed close: a close where every balance nets to zero
     * writes no rows, yet the period's opening is still close-derived —
     * hand-entry would desynchronise the snapshot chain.
     */
    public static function periodIsSystemGenerated(ReportingPeriod $period, Entity $entity): bool
    {
        $generatedRows = self::entityBalances($entity)
            ->where('reporting_period_id', $period->id)
            ->where('reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%-OB')
            ->exists();

        if ($generatedRows) {
            return true;
        }

        return FiscalYearClose::where('entity_id', $entity->id)
            ->where('year', $period->calendar_year - 1)
            ->where('status', FiscalYearClose::STATUS_CLOSED)
            ->exists();
    }

    /**
     * Balance rows for the entity, unscoped from the package's EntityScope
     * (it dereferences Auth::user()->entity->id and fatals for authed
     * users without an entity); the explicit entity_id filter keeps the
     * row set identical.
     */
    protected static function entityBalances(Entity $entity)
    {
        return Balance::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id);
    }

    protected static function entityPeriods(Entity $entity)
    {
        return ReportingPeriod::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id);
    }
}

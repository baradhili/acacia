<?php

namespace App\Services;

use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use IFRS\Scopes\EntityScope;

/**
 * Opening balances per the IFRS package's documented mechanism: Balance
 * rows on balance-sheet accounts, dated before their reporting period's
 * start. The package's own reports consume them via
 * Account::openingBalance() (closing = opening + period movement); the
 * app's cumulative ledger reports add them via effectiveOpening().
 */
class OpeningBalances
{
    /**
     * Signed (debit-positive) opening-balance contribution for an account:
     * the sum of its Balance rows (debit balances positive, credit
     * balances negative). Opening balances are entered before any ledger
     * activity, so this is exactly the account's opening position.
     */
    public static function effectiveOpening(Account $account, Entity $entity): float
    {
        $balances = self::entityBalances($entity)
            ->where('account_id', $account->id)
            ->get(['balance_type', 'balance']);

        return round($balances->sum(
            fn ($balance) => $balance->balance_type === Balance::CREDIT ? -$balance->balance : $balance->balance
        ), 2);
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

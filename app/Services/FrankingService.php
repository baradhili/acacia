<?php

namespace App\Services;

use App\Models\DividendDeclaration;
use App\Models\FrankingAccountEntry;
use Carbon\Carbon;
use IFRS\Models\Entity;
use IFRS\Models\ReportingPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The notional franking account: a lifetime running balance of franking
 * credits and debits that never posts to the GL. The financial_year column
 * groups entries for reporting (Australian FY, 1 July start); balances are
 * always computed from the entries, never stored.
 *
 * AASB 1054.13 disclosure adjusts the reporting-date balance for
 * anticipated movements: estimated entries (is_estimated, e.g. credits
 * expected from the current income tax provision) and the franking debits
 * that will arise when approved-but-unpaid dividends are settled.
 */
class FrankingService
{
    /**
     * Actual franking balance (excludes estimated entries) as at $asOf —
     * the credits attached to dividends paid to date, net of debits.
     */
    public static function balance($asOf = null, ?int $entityId = null): float
    {
        return round((float) self::entryQuery($asOf, $entityId)
            ->where('is_estimated', false)
            ->sum(DB::raw('credit_amount - debit_amount')), 2);
    }

    /**
     * Net estimated (anticipated) credits as at $asOf.
     */
    public static function estimatedNetCredits($asOf = null, ?int $entityId = null): float
    {
        return round((float) self::entryQuery($asOf, $entityId)
            ->where('is_estimated', true)
            ->sum(DB::raw('credit_amount - debit_amount')), 2);
    }

    /**
     * Franking debits committed but not yet incurred: approved declarations
     * whose payment has not been recorded (their FD entry lands when the
     * run is marked paid).
     */
    public static function pendingFrankingDebits(?int $entityId = null): float
    {
        return round((float) self::declarationsQuery($entityId)
            ->where('status', DividendDeclaration::STATUS_APPROVED)
            ->sum('total_franking_credit'), 2);
    }

    /**
     * Credits available for attaching to a new dividend as at $asOf: the
     * actual balance, plus estimated future credits, minus the debits
     * already committed to approved-but-unpaid declarations.
     */
    public static function availableBalance($asOf = null, ?int $entityId = null): float
    {
        return round(
            self::balance($asOf, $entityId)
            + self::estimatedNetCredits($asOf, $entityId)
            - self::pendingFrankingDebits($entityId),
            2,
        );
    }

    /**
     * First and last day of franking financial year $fy (1 July – 30 June
     * per config('australian.financial_year')).
     */
    public static function yearBounds(int $fy): array
    {
        $start = Carbon::create($fy, config('australian.financial_year.start_month', 7), config('australian.financial_year.start_day', 1));

        return [
            'start' => $start->copy()->startOfDay(),
            'end' => $start->copy()->addYear()->subDay()->endOfDay(),
        ];
    }

    public static function openingBalance(int $fy, ?int $entityId = null): float
    {
        return round((float) self::entryQuery(self::yearBounds($fy)['start']->subSecond(), $entityId)
            ->where('is_estimated', false)
            ->sum(DB::raw('credit_amount - debit_amount')), 2);
    }

    public static function closingBalance(int $fy, ?int $entityId = null): float
    {
        return self::balance(self::yearBounds($fy)['end'], $entityId);
    }

    /**
     * Net credit movement by entry type within $fy (excludes estimated
     * entries unless requested).
     *
     * @return array<string, float>
     */
    public static function movementsByType(int $fy, ?int $entityId = null, bool $includeEstimated = false): array
    {
        ['start' => $start, 'end' => $end] = self::yearBounds($fy);

        return self::baseQuery($entityId)
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->when(! $includeEstimated, fn ($q) => $q->where('is_estimated', false))
            ->groupBy('entry_type')
            ->selectRaw('entry_type, SUM(credit_amount - debit_amount) as net')
            ->pluck('net', 'entry_type')
            ->map(fn ($net) => round((float) $net, 2))
            ->all();
    }

    /**
     * A franking year that closes in deficit means franking deficit tax is
     * payable — flagged as a warning (FDT handling is manual per the spec's
     * scope exclusions).
     */
    public static function hasDeficit(int $fy, ?int $entityId = null): bool
    {
        return self::closingBalance($fy, $entityId) < 0;
    }

    /**
     * Distinct franking years with entries, descending — the report year
     * selector. The current FY is always offered.
     */
    public static function years(?int $entityId = null): array
    {
        $years = self::baseQuery($entityId)
            ->distinct()
            ->orderByDesc('financial_year')
            ->pluck('financial_year')
            ->all();

        $entity = IfrsPosting::resolveEntity();
        $current = $entity ? (int) ReportingPeriod::year(now(), $entity) : (int) now()->year;
        if (! in_array($current, $years, true)) {
            $years[] = $current;
            rsort($years);
        }

        return array_map('intval', $years);
    }

    /**
     * AASB 1054.13 disclosure figures for $fy.
     *
     * @return array{year: int, closing_balance: float, anticipated_credits: float, anticipated_debits: float, available: float, deficit: bool}
     */
    public static function disclosureData(int $fy, ?int $entityId = null): array
    {
        ['end' => $end] = self::yearBounds($fy);

        $anticipatedDebits = round((float) self::declarationsQuery($entityId)
            ->where('status', DividendDeclaration::STATUS_APPROVED)
            ->whereDate('approved_at', '<=', $end->toDateString())
            ->sum('total_franking_credit'), 2);

        $closing = self::closingBalance($fy, $entityId);
        $anticipatedCredits = self::estimatedNetCredits($end, $entityId);

        return [
            'year' => $fy,
            'closing_balance' => $closing,
            'anticipated_credits' => $anticipatedCredits,
            'anticipated_debits' => $anticipatedDebits,
            'available' => round($closing + $anticipatedCredits - $anticipatedDebits, 2),
            'deficit' => $closing < 0,
        ];
    }

    /**
     * Create a manual franking entry (TC/DR/RF/FT/AJ/OB — FD entries are
     * system-generated by DividendService). Exactly one side must carry an
     * amount; the financial year is derived from the entry date so it can
     * never disagree with the reporting calendar. OB entries are the
     * exception on both counts: they must sit on the eve of the financial
     * year they open (30 June for a July start) and belong to that year, so
     * they carry forward into it without polluting its movement summary —
     * and only one may exist per financial year.
     */
    public static function recordEntry(array $attributes): FrankingAccountEntry
    {
        $type = $attributes['entry_type'] ?? '';
        if (! in_array($type, FrankingAccountEntry::MANUAL_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid or system-reserved franking entry type.');
        }

        $credit = round((float) ($attributes['credit_amount'] ?? 0), 2);
        $debit = round((float) ($attributes['debit_amount'] ?? 0), 2);
        if ($credit < 0 || $debit < 0) {
            throw new \InvalidArgumentException('Franking amounts cannot be negative.');
        }
        if (($credit > 0) === ($debit > 0)) {
            throw new \InvalidArgumentException('Exactly one of credit or debit amount must be greater than zero.');
        }

        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 503, 'No IFRS entity available for the franking account.');

        $date = Carbon::parse($attributes['entry_date']);
        $financialYear = self::financialYearFor($date, $entity);

        if ($type === FrankingAccountEntry::TYPE_OPENING_BALANCE) {
            $yearStart = $date->copy()->addDay();
            if (
                $yearStart->month !== (int) config('australian.financial_year.start_month', 7)
                || $yearStart->day !== (int) config('australian.financial_year.start_day', 1)
            ) {
                throw new \InvalidArgumentException(
                    'Opening balance entries must be dated the day before the financial year starts '
                    .'(e.g. 30 Jun for a July year start).'
                );
            }

            $financialYear = self::financialYearFor($yearStart, $entity);

            $already = FrankingAccountEntry::query()
                ->where('entity_id', $entity->id)
                ->where('entry_type', FrankingAccountEntry::TYPE_OPENING_BALANCE)
                ->where('financial_year', $financialYear)
                ->exists();
            if ($already) {
                throw new \InvalidArgumentException(
                    "FY {$financialYear} already has an opening balance. Delete it before recording a new one."
                );
            }
        }

        return FrankingAccountEntry::create([
            'entity_id' => $entity->id,
            'financial_year' => $financialYear,
            'entry_date' => $date->toDateString(),
            'entry_type' => $type,
            'reference' => $attributes['reference'] ?? null,
            'description' => $attributes['description'] ?? null,
            'credit_amount' => $credit,
            'debit_amount' => $debit,
            'is_estimated' => $type === FrankingAccountEntry::TYPE_OPENING_BALANCE
                ? false
                : (bool) ($attributes['is_estimated'] ?? false),
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Franking FY label for a date — same 1 July derivation as the IFRS
     * reporting periods (FY 2025 = 1 Jul 2025 – 30 Jun 2026).
     */
    public static function financialYearFor($date, ?Entity $entity = null): int
    {
        $entity ??= IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 503, 'No IFRS entity available.');

        return (int) ReportingPeriod::year(Carbon::parse($date), $entity);
    }

    protected static function baseQuery(?int $entityId = null)
    {
        return FrankingAccountEntry::query()
            ->when($entityId ?? IfrsPosting::resolveEntity()?->id, fn ($q, $id) => $q->where('entity_id', $id));
    }

    protected static function entryQuery($asOf = null, ?int $entityId = null)
    {
        $asOf = $asOf ? Carbon::parse($asOf)->endOfDay() : null;

        return self::baseQuery($entityId)
            ->when($asOf, fn ($q) => $q->whereDate('entry_date', '<=', $asOf->toDateString()));
    }

    protected static function declarationsQuery(?int $entityId = null)
    {
        return DividendDeclaration::query()
            ->when($entityId ?? IfrsPosting::resolveEntity()?->id, fn ($q, $id) => $q->where('entity_id', $id));
    }
}

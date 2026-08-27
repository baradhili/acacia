<?php

namespace App\Services;

use App\Models\BillPayment;
use App\Models\Prepayment;
use App\Models\PrepaymentAmortisation;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Entity;
use IFRS\Models\LineItem;
use IFRS\Transactions\JournalEntry;
use Illuminate\Support\Facades\Log;

/**
 * Creates and amortises prepaid service contracts (subscriptions,
 * licences, prepaid domain renewals, finite-life intangibles).
 *
 * A payment against a prepaid bill line debits the line's account (the
 * prepaid asset); createFromPayment() then records a Prepayment row for
 * the funded ex-GST share so the amortisation runner can expense it
 * monthly over the service period. Shares are apportioned in cents with
 * the same last-item-takes-remainder rule as BillPayment::postToIFRS()
 * so schedule totals always equal what actually hit the ledger.
 */
class PrepaymentService
{
    /**
     * Record Prepayment rows for the prepaid bill lines a payment funds.
     * Called after the ledger posting succeeds; best-effort (logged,
     * non-fatal) — the ledger entry is authoritative either way.
     *
     * @return Prepayment[] the created rows
     */
    public static function createFromPayment(BillPayment $payment, Entity $entity): array
    {
        $created = [];

        $defaultExpense = Account::where('entity_id', $entity->id)
            ->where('code', config('subscriptions.subscription_expense_code', 7500))
            ->first();

        foreach ($payment->allocations as $allocation) {
            $bill = $allocation->bill()->with('items')->first();
            if (!$bill) {
                continue;
            }

            $items = $bill->items->where('is_prepaid', true)->values();
            if ($items->isEmpty()) {
                continue;
            }

            $billTotalCents = (int) round(((float) $bill->total) * 100);
            $allocationCents = (int) round(((float) $allocation->amount) * 100);
            if ($billTotalCents <= 0 || $allocationCents <= 0) {
                continue;
            }

            // All bill items take part in the share denominators (the
            // posting apportions across every line), but only prepaid
            // lines spawn schedules.
            $lastIndex = $bill->items->count() - 1;
            $distributed = 0;
            foreach ($bill->items->values() as $index => $item) {
                $shareCents = $index === $lastIndex
                    ? $allocationCents - $distributed
                    : (int) round($allocationCents * ((float) $item->total * 100) / $billTotalCents);
                $distributed += $shareCents;

                if (!$item->is_prepaid || $shareCents <= 0) {
                    continue;
                }
                if (!$item->expense_account_id) {
                    Log::warning('Prepaid bill item has no account — prepayment not created', [
                        'bill_item_id' => $item->id,
                        'bill_payment_id' => $payment->id,
                    ]);
                    continue;
                }

                // Ex-GST funded share: the item's net (total − tax)
                // fraction of its tax-inclusive share.
                $itemTotalCents = (int) round(((float) $item->total) * 100);
                $itemNetCents = (int) round(((float) $item->total - (float) $item->tax_amount) * 100);
                $netCents = (int) round($shareCents * $itemNetCents / max(1, $itemTotalCents));
                if ($netCents <= 0) {
                    continue;
                }

                $serviceStart = Carbon::parse($item->service_start);
                $serviceEnd = Carbon::parse($item->service_end);
                $periods = self::periodCount($serviceStart, $serviceEnd);
                $total = round($netCents / 100, 2);

                $created[] = $payment->prepayments()->create([
                    'entity_id' => $entity->id,
                    'bill_item_id' => $item->id,
                    'description' => $item->description,
                    'asset_account_id' => $item->expense_account_id,
                    'expense_account_id' => $item->amortise_to_account_id
                        ?? $defaultExpense?->id
                        ?? $item->expense_account_id,
                    'service_start' => $serviceStart->toDateString(),
                    'service_end' => $serviceEnd->toDateString(),
                    'periods' => $periods,
                    'total_amount' => $total,
                    'monthly_amount' => round($total / $periods, 2),
                    'next_period_date' => $serviceStart->copy()->endOfMonth()->toDateString(),
                    'status' => Prepayment::STATUS_ACTIVE,
                ]);
            }
        }

        return $created;
    }

    /**
     * Whole months covered by the service period (a 1 Jul – 30 Jun
     * annual subscription is 12 months), minimum 1.
     */
    public static function periodCount(Carbon $start, Carbon $end): int
    {
        if ($end->lessThan($start)) {
            return 1;
        }

        return max(1, (int) round($start->floatDiffInMonths($end)));
    }

    /**
     * Post the monthly amortisation entries that have fallen due, as at
     * $asOf (defaults to today). A catch-up loop rather than a single
     * cursor step, so missed runs recover; each month posts its own
     * JournalEntry dated at that month's end, landing in whichever
     * financial year the month falls (todo-list #9 proration).
     *
     *      Dr expense_account   (e.g. 7500 Subscriptions & Licenses)
     *      Cr asset_account     (e.g. 460 Prepaid Subscriptions)
     *
     * The cursor only advances after a successful post, so a closed IFRS
     * reporting period stops that prepayment for now and retries on the
     * next run. Throws on the first failure — callers continue with
     * other prepayments.
     *
     * @return int the number of months posted (or that would post, dry run)
     */
    public static function amortise(Prepayment $prepayment, ?Carbon $asOf = null, bool $dryRun = false): int
    {
        if ($prepayment->status !== Prepayment::STATUS_ACTIVE) {
            return 0;
        }

        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 503, 'No IFRS entity available for amortisation.');

        $asOf = Carbon::parse($asOf ?? today())->endOfDay();
        $limit = $asOf->min($prepayment->service_end->copy()->endOfMonth());

        $lockService = app(\App\Services\PeriodLockService::class);
        $posted = 0;

        $prepayment->refresh();
        while ($prepayment->next_period_date->lte($limit)) {
            $periodDate = $prepayment->next_period_date->copy();

            // A row for this month already existing means the cursor is
            // behind the schedule (e.g. after a manual data fix) — catch
            // the cursor up without posting twice.
            $exists = $prepayment->amortisations()
                ->where('period_date', $periodDate->toDateString())
                ->exists();
            if ($exists) {
                $prepayment->forceFill([
                    'next_period_date' => self::nextMonthEnd($periodDate),
                ])->save();
                continue;
            }

            // The final period absorbs the rounding remainder so the
            // schedule sums exactly to the funded amount.
            $position = $prepayment->amortisations()->count() + 1;
            $amount = $position >= $prepayment->periods
                ? round((float) $prepayment->total_amount - ($prepayment->monthly_amount * ($prepayment->periods - 1)), 2)
                : (float) $prepayment->monthly_amount;

            if ($dryRun) {
                $posted++;
                $prepayment->next_period_date = Carbon::parse(self::nextMonthEnd($periodDate));
                continue;
            }

            // Voluntary app-level lock check (nothing else enforces it
            // for console posting).
            if ($lockService->isDateLocked($periodDate)) {
                Log::warning('Prepayment amortisation skipped — app period lock', [
                    'prepayment_id' => $prepayment->id,
                    'period_date' => $periodDate->toDateString(),
                ]);
                break;
            }

            IfrsPosting::ensureReportingPeriod($periodDate, $entity);

            // Mirror of BillPayment::postToIFRS(): main account credited
            // (Cr prepaid asset) with the expense line flipped to Dr.
            $journalEntry = new JournalEntry([
                'transaction_date' => IfrsPosting::transactionDate($periodDate, $entity),
                'account_id' => $prepayment->asset_account_id,
                'credited' => true,
                'entity_id' => $entity->id,
                'narration' => "Prepayment amortisation: {$prepayment->description}",
                'reference' => 'PREPAY-' . $prepayment->id,
            ]);

            // Persisted before addLineItem() — unsaved items share a
            // null id and the package silently drops all but the first.
            $line = LineItem::create([
                'account_id' => $prepayment->expense_account_id,
                'amount' => $amount,
                'quantity' => 1,
                'entity_id' => $entity->id,
            ]);
            $journalEntry->addLineItem($line);
            $journalEntry->post();

            $prepayment->amortisations()->create([
                'period_date' => $periodDate->toDateString(),
                'amount' => $amount,
                'ifrs_transaction_id' => $journalEntry->id,
            ]);

            $posted++;
            $prepayment->forceFill([
                'next_period_date' => self::nextMonthEnd($periodDate),
            ])->save();
        }

        if (!$dryRun) {
            $remainingPeriods = $prepayment->periods - $prepayment->amortisations()->count();
            if ($remainingPeriods <= 0) {
                $prepayment->forceFill(['status' => Prepayment::STATUS_COMPLETED])->save();
            }
        }

        return $posted;
    }

    /**
     * Reverse one posted amortisation entry with a same-date mirrored
     * JournalEntry (posted entries are never mutated or deleted). The
     * month stays consumed — post a correcting entry if the amount was
     * wrong. With $throw = true a reversal failure propagates instead of
     * being logged-and-skipped, for flows (bill deletion) that must not
     * continue with a partially reversed ledger.
     */
    public static function reverseAmortisation(PrepaymentAmortisation $entry, bool $throw = false): ?int
    {
        if (!$entry->isPosted() || $entry->isReversed()) {
            return null;
        }

        try {
            $reversalId = IfrsPosting::reverseTransaction(
                (int) $entry->ifrs_transaction_id,
                'Reversal of prepayment amortisation: ' . $entry->prepayment?->description,
                'PREPAY-' . $entry->prepayment_id,
                throw: true,
            );
        } catch (\Throwable $e) {
            if ($throw) {
                throw $e;
            }
            \Illuminate\Support\Facades\Log::error('Failed to reverse prepayment amortisation', [
                'prepayment_amortisation_id' => $entry->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return null;
        }

        if ($reversalId) {
            $entry->update([
                'reversal_transaction_id' => $reversalId,
                'reversed_at' => now(),
            ]);
        }

        return $reversalId;
    }

    /**
     * The full schedule for a prepayment: posted entries (with any
     * reversals) plus the still-planned months up to the service end —
     * used by the review screen and the schedule report.
     */
    public static function scheduleWithPlanned(Prepayment $prepayment): array
    {
        $rows = $prepayment->amortisations->map(fn ($entry) => [
            'period_date' => $entry->period_date,
            'amount' => (float) $entry->amount,
            'posted' => true,
            'reversed' => $entry->isReversed(),
            'transaction_id' => $entry->ifrs_transaction_id,
            'entry' => $entry,
        ])->all();

        $position = count($rows);
        $cursor = $prepayment->status === Prepayment::STATUS_ACTIVE
            ? $prepayment->next_period_date->copy()
            : null;
        $finalEnd = $prepayment->service_end->copy()->endOfMonth();

        while ($cursor && $cursor->lte($finalEnd) && $position < $prepayment->periods) {
            $position++;
            $amount = $position >= $prepayment->periods
                ? round((float) $prepayment->total_amount - ((float) $prepayment->monthly_amount * ($prepayment->periods - 1)), 2)
                : (float) $prepayment->monthly_amount;

            $rows[] = [
                'period_date' => $cursor->copy(),
                'amount' => $amount,
                'posted' => false,
                'reversed' => false,
                'transaction_id' => null,
                'entry' => null,
            ];

            $cursor = Carbon::parse(self::nextMonthEnd($cursor));
        }

        return $rows;
    }

    public static function nextMonthEnd(Carbon $periodDate): string
    {
        // startOfMonth first: adding a month to a 31st would overflow
        // (31 Aug + 1 month = 1 Oct in Carbon) and skip short months.
        return $periodDate->copy()->startOfMonth()->addMonth()->endOfMonth()->toDateString();
    }
}

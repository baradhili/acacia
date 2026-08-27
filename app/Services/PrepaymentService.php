<?php

namespace App\Services;

use App\Models\BillPayment;
use App\Models\Prepayment;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Entity;
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
}

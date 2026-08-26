<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bill unit prices used to be stored GST-EXCLUSIVE (BillItem added 10% on
 * top to reach the total). Australian supplier bills quote GST-inclusive
 * amounts, so entry is now inclusive: the entered price IS the amount paid
 * and the GST portion is back-calculated from it.
 *
 * Convert stored rows to the new convention: gross the taxable unit prices
 * up to their inclusive value (the saving hook then recalculates tax/total
 * and the bill totals under the new formula). Stored totals are effectively
 * unchanged — a $100 ex-GST line (+$10 GST, $110 total) becomes a $110
 * inclusive price with $10 GST back-calculated and the same $110 total.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $items = DB::table('bill_items')->get();

            foreach ($items as $item) {
                $taxRate = (float) $item->tax_rate;
                $unitPrice = (float) $item->unit_price;

                if ($taxRate > 0) {
                    $unitPrice = round($unitPrice * (1 + $taxRate / 100), 2);
                }

                // Recalculate this row under the inclusive formula, matching
                // BillItem::calculateTotals() without needing the model
                // (migrations must not depend on app code drift).
                $gross = (float) $item->quantity * $unitPrice;
                $discountPercent = (float) $item->discount_percent;
                $discountAmount = $discountPercent > 0
                    ? round($gross * ($discountPercent / 100), 2)
                    : 0;
                $total = round($gross - $discountAmount, 2);
                $taxAmount = $taxRate > 0
                    ? round($total * $taxRate / (100 + $taxRate), 2)
                    : 0;

                DB::table('bill_items')->where('id', $item->id)->update([
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                ]);
            }

            // Rebuild each bill's totals from the converted items.
            $bills = DB::table('bills')
                ->join('bill_items', 'bills.id', '=', 'bill_items.bill_id')
                ->select('bills.id')
                ->selectRaw('SUM(bill_items.total - bill_items.tax_amount) as subtotal')
                ->selectRaw('SUM(bill_items.tax_amount) as tax_amount')
                ->selectRaw('SUM(bill_items.discount_amount) as discount_amount')
                ->groupBy('bills.id')
                ->get();

            foreach ($bills as $bill) {
                DB::table('bills')->where('id', $bill->id)->update([
                    'subtotal' => round($bill->subtotal, 2),
                    'tax_amount' => round($bill->tax_amount, 2),
                    'discount_amount' => round($bill->discount_amount, 2),
                    'total' => round($bill->subtotal + $bill->tax_amount, 2),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Reverse to GST-exclusive prices: strip the tax back out of the
        // unit price and re-derive tax/total the old way.
        DB::transaction(function () {
            $items = DB::table('bill_items')->get();

            foreach ($items as $item) {
                $taxRate = (float) $item->tax_rate;
                $unitPrice = (float) $item->unit_price;

                if ($taxRate > 0) {
                    $unitPrice = round($unitPrice / (1 + $taxRate / 100), 2);
                }

                $gross = (float) $item->quantity * $unitPrice;
                $discountPercent = (float) $item->discount_percent;
                $discountAmount = $discountPercent > 0
                    ? round($gross * ($discountPercent / 100), 2)
                    : 0;
                $afterDiscount = $gross - $discountAmount;
                $taxAmount = $taxRate > 0 ? round($afterDiscount * $taxRate / 100, 2) : 0;

                DB::table('bill_items')->where('id', $item->id)->update([
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                    'total' => round($afterDiscount + $taxAmount, 2),
                ]);
            }

            $bills = DB::table('bills')
                ->join('bill_items', 'bills.id', '=', 'bill_items.bill_id')
                ->select('bills.id')
                ->selectRaw('SUM(bill_items.quantity * bill_items.unit_price - bill_items.discount_amount) as subtotal')
                ->selectRaw('SUM(bill_items.tax_amount) as tax_amount')
                ->selectRaw('SUM(bill_items.discount_amount) as discount_amount')
                ->groupBy('bills.id')
                ->get();

            foreach ($bills as $bill) {
                DB::table('bills')->where('id', $bill->id)->update([
                    'subtotal' => round($bill->subtotal, 2),
                    'tax_amount' => round($bill->tax_amount, 2),
                    'discount_amount' => round($bill->discount_amount, 2),
                    'total' => round($bill->subtotal + $bill->tax_amount, 2),
                ]);
            }
        });
    }
};

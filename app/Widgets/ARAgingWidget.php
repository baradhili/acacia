<?php

namespace App\Widgets;

use App\Models\Invoice;
use Carbon\Carbon;
use Arrilot\Widgets\AbstractWidget;

class ARAgingWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $today = Carbon::now();

        $outstandingInvoices = Invoice::whereIn('status', [
            Invoice::STATUS_SENT,
            Invoice::STATUS_PARTIALLY_PAID,
            Invoice::STATUS_OVERDUE,
        ])->get();

        $current = 0;
        $days30 = 0;
        $days60 = 0;
        $days90 = 0;
        $over90 = 0;

        foreach ($outstandingInvoices as $invoice) {
            $daysPastDue = $invoice->due_date->diffInDays($today, false);
            $balance = $invoice->amount_due;

            if ($daysPastDue <= 0) {
                $current += $balance;
            } elseif ($daysPastDue <= 30) {
                $days30 += $balance;
            } elseif ($daysPastDue <= 60) {
                $days60 += $balance;
            } elseif ($daysPastDue <= 90) {
                $days90 += $balance;
            } else {
                $over90 += $balance;
            }
        }

        $total = $current + $days30 + $days60 + $days90 + $over90;

        return view('widgets.ar_aging', [
            'current' => $current,
            'days_30' => $days30,
            'days_60' => $days60,
            'days_90' => $days90,
            'over_90' => $over90,
            'total' => $total,
            'current_formatted' => number_format($current, 2),
            'days_30_formatted' => number_format($days30, 2),
            'days_60_formatted' => number_format($days60, 2),
            'days_90_formatted' => number_format($days90, 2),
            'over_90_formatted' => number_format($over90, 2),
            'total_formatted' => number_format($total, 2),
            'aging_buckets' => [
                ['label' => 'Current', 'amount' => $current, 'percent' => $total > 0 ? round($current / $total * 100, 1) : 0],
                ['label' => '1-30 Days', 'amount' => $days30, 'percent' => $total > 0 ? round($days30 / $total * 100, 1) : 0],
                ['label' => '31-60 Days', 'amount' => $days60, 'percent' => $total > 0 ? round($days60 / $total * 100, 1) : 0],
                ['label' => '61-90 Days', 'amount' => $days90, 'percent' => $total > 0 ? round($days90 / $total * 100, 1) : 0],
                ['label' => '90+ Days', 'amount' => $over90, 'percent' => $total > 0 ? round($over90 / $total * 100, 1) : 0],
            ],
        ]);
    }
}

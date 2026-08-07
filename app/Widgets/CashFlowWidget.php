<?php

namespace App\Widgets;

use App\Models\Payment;
use App\Models\Expense;
use Carbon\Carbon;
use Arrilot\Widgets\AbstractWidget;

class CashFlowWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $today = Carbon::now();
        $thirtyDaysAgo = $today->copy()->subDays(30);
        $sixtyDaysAgo = $today->copy()->subDays(60);

        $inflows = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $thirtyDaysAgo)
            ->where('payment_date', '<=', $today)
            ->sum('amount');

        $previousInflows = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $sixtyDaysAgo)
            ->where('payment_date', '<', $thirtyDaysAgo)
            ->sum('amount');

        $outflows = Expense::where('status', Expense::STATUS_PAID)
            ->where('paid_at', '>=', $thirtyDaysAgo)
            ->where('paid_at', '<=', $today)
            ->sum('total');

        $previousOutflows = Expense::where('status', Expense::STATUS_PAID)
            ->where('paid_at', '>=', $sixtyDaysAgo)
            ->where('paid_at', '<', $thirtyDaysAgo)
            ->sum('total');

        $netCashFlow = $inflows - $outflows;
        $previousNet = $previousInflows - $previousOutflows;
        $change = $previousNet != 0 ? (($netCashFlow - $previousNet) / abs($previousNet)) * 100 : 0;

        return view('widgets.cash_flow', [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'net_flow' => $netCashFlow,
            'change_percent' => round($change, 1),
            'inflows_formatted' => number_format($inflows, 2),
            'outflows_formatted' => number_format($outflows, 2),
            'net_flow_formatted' => number_format($netCashFlow, 2),
        ]);
    }
}

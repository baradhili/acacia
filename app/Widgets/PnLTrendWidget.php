<?php

namespace App\Widgets;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Expense;
use Carbon\Carbon;
use Arrilot\Widgets\AbstractWidget;

class PnLTrendWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $data = [];
        $today = Carbon::now();

        for ($i = 11; $i >= 0; $i--) {
            $monthStart = $today->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $revenue = Payment::where('status', Payment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $expenses = Expense::where('status', Expense::STATUS_PAID)
                ->whereBetween('paid_date', [$monthStart, $monthEnd])
                ->sum('total');

            $invoicesIssued = Invoice::whereBetween('issue_date', [$monthStart, $monthEnd])
                ->sum('total');

            $outstanding = Invoice::whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ])->get()->sum(fn($inv) => $inv->amount_due);

            $netIncome = $revenue - $expenses;

            $data[] = [
                'month' => $monthStart->format('Y-m'),
                'label' => $monthStart->format('M Y'),
                'revenue' => (float) $revenue,
                'expenses' => (float) $expenses,
                'net_income' => (float) $netIncome,
                'invoices_issued' => (float) $invoicesIssued,
                'outstanding' => (float) $outstanding,
            ];
        }

        $avgRevenue = collect($data)->avg('revenue');
        $avgExpenses = collect($data)->avg('expenses');
        $avgNetIncome = collect($data)->avg('net_income');

        return view('widgets.pnl_trend', [
            'months' => $data,
            'avg_revenue' => round($avgRevenue, 2),
            'avg_expenses' => round($avgExpenses, 2),
            'avg_net_income' => round($avgNetIncome, 2),
            'total_revenue' => collect($data)->sum('revenue'),
            'total_expenses' => collect($data)->sum('expenses'),
            'total_net_income' => collect($data)->sum('net_income'),
        ]);
    }
}

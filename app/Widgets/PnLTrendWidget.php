<?php

namespace App\Widgets;

use App\Models\BillPayment;
use App\Models\Invoice;
use App\Models\Payment;
use Arrilot\Widgets\AbstractWidget;
use Carbon\Carbon;

/**
 * Cash-basis monthly trend over the trailing 12 whole calendar
 * months: revenue is completed client payments and expenses are
 * completed supplier payments — invoiced amounts never count as
 * income here, matching how the app reports over the IFRS ledger.
 * Averages are unweighted means of the twelve monthly buckets.
 */
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

            $expenses = BillPayment::where('status', BillPayment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $invoicesIssued = Invoice::whereBetween('issue_date', [$monthStart, $monthEnd])
                ->sum('total');

            $outstanding = Invoice::whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ])->get()->sum(fn ($inv) => $inv->amount_due);

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

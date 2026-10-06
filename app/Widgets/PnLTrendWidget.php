<?php

namespace App\Widgets;

use App\Models\BillPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReimbursementPayment;
use App\Services\IfrsPosting;
use Arrilot\Widgets\AbstractWidget;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use Illuminate\Support\Facades\DB;

/**
 * Cash-basis monthly trend over the trailing 12 whole calendar
 * months: revenue is completed client payments and expenses are
 * completed supplier payments, employee reimbursements, and the cash
 * actually paid on payroll — the bank legs of the net-pay journals
 * (PAYROLL-*-PAY) and the super / PAYG-withholding settlements
 * (PAYSET-*, BAS-SETT-PAYG-*), counted in the month the money left
 * the bank. Accrued-but-unpaid withholding never counts until it
 * settles, and reversed rounds are excluded by their -REV reference.
 * Invoiced amounts never count as income here, matching how the app
 * reports over the IFRS ledger. Averages are unweighted means of the
 * twelve monthly buckets.
 */
class PnLTrendWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $data = [];
        $today = Carbon::now();

        // Payroll and reimbursements are fetched once over the whole
        // window and bucketed, not queried per month.
        $windowStart = $today->copy()->subMonths(11)->startOfMonth();
        $windowEnd = $today->copy()->endOfMonth();

        $reimbursementsByMonth = ReimbursementPayment::where('status', ReimbursementPayment::STATUS_COMPLETED)
            ->whereBetween('payment_date', [$windowStart, $windowEnd])
            ->get(['amount', 'payment_date'])
            ->groupBy(fn ($payment) => $payment->payment_date->format('Y-m'))
            ->map(fn ($payments) => (float) $payments->sum('amount'));

        // The cash that left the bank for payroll: the bank legs of the
        // payroll payment journals, netted per reference family so an
        // abandoned settle/reverse round (its -REV mirror) contributes
        // nothing, and attributed to the family's latest movement. The
        // pay run's accrual journals never touch the bank and an unpaid
        // PAYG or super liability stays out until its settlement posts —
        // the EntityScope cannot resolve every widget context, so the
        // entity is filtered explicitly (the TaxReportController
        // precedent) and no entity means no payroll cash to count.
        $payrollByMonth = collect();
        $entity = IfrsPosting::resolveEntity();
        if ($entity !== null) {
            $families = DB::table('ifrs_ledgers as l')
                ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
                ->join('ifrs_accounts as bank', 'bank.id', '=', 'l.post_account')
                ->where('bank.entity_id', $entity->id)
                ->where('bank.account_type', Account::BANK)
                ->whereBetween('l.posting_date', [$windowStart, $windowEnd])
                ->whereNull('l.deleted_at')
                ->whereNull('t.deleted_at')
                ->where(fn ($query) => $query
                    ->where('t.reference', 'like', 'PAYROLL-%-PAY')
                    ->orWhere('t.reference', 'like', 'PAYSET-%')
                    ->orWhere('t.reference', 'like', 'BAS-SETT-PAYG-%'))
                ->get(['t.reference', 'l.posting_date', 'l.entry_type', 'l.amount'])
                ->groupBy(fn ($row) => preg_replace('/-REV$/', '', $row->reference));

            foreach ($families as $rows) {
                $net = round((float) $rows->sum(
                    fn ($row) => $row->entry_type === Balance::CREDIT ? $row->amount : -$row->amount
                ), 2);

                if (abs($net) < 0.005) {
                    continue;
                }

                $month = Carbon::parse($rows->max('posting_date'))->format('Y-m');
                $payrollByMonth[$month] = round(($payrollByMonth[$month] ?? 0) + $net, 2);
            }
        }

        for ($i = 11; $i >= 0; $i--) {
            $monthStart = $today->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $monthKey = $monthStart->format('Y-m');

            $revenue = Payment::where('status', Payment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount');

            $expenses = (float) BillPayment::where('status', BillPayment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount');
            $expenses += $reimbursementsByMonth->get($monthKey, 0.0);
            $expenses += $payrollByMonth->get($monthKey, 0.0);

            $invoicesIssued = Invoice::whereBetween('issue_date', [$monthStart, $monthEnd])
                ->sum('total');

            $outstanding = Invoice::whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ])->get()->sum(fn ($inv) => $inv->amount_due);

            $netIncome = $revenue - $expenses;

            $data[] = [
                'month' => $monthKey,
                'label' => $monthStart->format('M Y'),
                'revenue' => (float) $revenue,
                'expenses' => $expenses,
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

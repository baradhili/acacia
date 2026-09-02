<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\BillPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use Carbon\Carbon;

class DashboardService
{
    /**
     * Get all dashboard widget data
     */
    public function getAllWidgets(): array
    {
        return [
            'cash_flow' => $this->getCashFlowWidget(),
            'ar_aging' => $this->getARAgingWidget(),
            'recent_invoices' => $this->getRecentInvoicesWidget(),
            'recent_payments' => $this->getRecentPaymentsWidget(),
            'outstanding_po_budgets' => $this->getOutstandingPOBudgetsWidget(),
            'unbilled_time' => $this->getUnbilledTimeWidget(),
            'bank_balance' => $this->getBankBalanceWidget(),
            'pnl_trend' => $this->getPnLTrendWidget(),
        ];
    }

    /**
     * Widget: Cash Flow (30-day)
     */
    public function getCashFlowWidget(): array
    {
        $today = Carbon::now();
        $thirtyDaysAgo = $today->copy()->subDays(30);
        $sixtyDaysAgo = $today->copy()->subDays(60);

        // Inflows (payments received) in last 30 days
        $inflows = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $thirtyDaysAgo)
            ->where('payment_date', '<=', $today)
            ->sum('amount');

        // Previous period inflows for comparison
        $previousInflows = Payment::where('status', Payment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $sixtyDaysAgo)
            ->where('payment_date', '<', $thirtyDaysAgo)
            ->sum('amount');

        // Outflows (supplier payments) in last 30 days
        $outflows = BillPayment::where('status', BillPayment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $thirtyDaysAgo)
            ->where('payment_date', '<=', $today)
            ->sum('amount');

        // Previous period outflows
        $previousOutflows = BillPayment::where('status', BillPayment::STATUS_COMPLETED)
            ->where('payment_date', '>=', $sixtyDaysAgo)
            ->where('payment_date', '<', $thirtyDaysAgo)
            ->sum('amount');

        $netCashFlow = $inflows - $outflows;
        $previousNet = $previousInflows - $previousOutflows;
        $change = $previousNet != 0 ? (($netCashFlow - $previousNet) / abs($previousNet)) * 100 : 0;

        // Daily breakdown for chart
        $dailyData = $this->getDailyCashFlow($thirtyDaysAgo, $today);

        return [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'net_flow' => $netCashFlow,
            'change_percent' => round($change, 1),
            'inflows_formatted' => number_format($inflows, 2),
            'outflows_formatted' => number_format($outflows, 2),
            'net_flow_formatted' => number_format($netCashFlow, 2),
            'daily_data' => $dailyData,
        ];
    }

    /**
     * Get daily cash flow data for chart
     */
    protected function getDailyCashFlow(Carbon $start, Carbon $end): array
    {
        $data = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dayStart = $date->copy()->startOfDay();
            $dayEnd = $date->copy()->endOfDay();

            $inflow = Payment::where('status', Payment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$dayStart, $dayEnd])
                ->sum('amount');

            $outflow = BillPayment::where('status', BillPayment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$dayStart, $dayEnd])
                ->sum('amount');

            $data[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('M d'),
                'inflow' => (float) $inflow,
                'outflow' => (float) $outflow,
                'net' => (float) ($inflow - $outflow),
            ];
        }

        return $data;
    }

    /**
     * Widget: AR Aging Summary
     */
    public function getARAgingWidget(): array
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

        return [
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
        ];
    }

    /**
     * Widget: Recent Invoices
     */
    public function getRecentInvoicesWidget(int $limit = 10): array
    {
        $invoices = Invoice::with('client')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client_name' => $invoice->client?->name ?? 'Unknown',
                    'total' => $invoice->total,
                    'total_formatted' => number_format($invoice->total, 2),
                    'status' => $invoice->status,
                    'issue_date' => $invoice->issue_date?->format('Y-m-d'),
                    'due_date' => $invoice->due_date?->format('Y-m-d'),
                    'is_overdue' => $invoice->is_overdue,
                    'amount_due' => $invoice->amount_due,
                ];
            });

        return [
            'invoices' => $invoices,
            'count' => $invoices->count(),
            'total_amount' => $invoices->sum('total'),
        ];
    }

    /**
     * Widget: Recent Payments
     */
    public function getRecentPaymentsWidget(int $limit = 10): array
    {
        $payments = Payment::with('client')
            ->where('status', Payment::STATUS_COMPLETED)
            ->orderBy('payment_date', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'client_name' => $payment->client?->name ?? 'Unknown',
                    'amount' => $payment->amount,
                    'amount_formatted' => number_format($payment->amount, 2),
                    'payment_date' => $payment->payment_date?->format('Y-m-d'),
                    'method' => $payment->payment_method,
                ];
            });

        return [
            'payments' => $payments,
            'count' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'total_formatted' => number_format($payments->sum('amount'), 2),
        ];
    }

    /**
     * Widget: Outstanding PO Budgets
     */
    public function getOutstandingPOBudgetsWidget(): array
    {
        $purchaseOrders = PurchaseOrder::with('project.client')
            ->whereIn('status', [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIALLY_USED])
            ->get()
            ->map(function ($po) {
                $total = (float) $po->budgeted_amount;
                $spent = (float) $po->used_amount;
                $remaining = $po->remaining;
                $utilization = $po->utilization;

                return [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'project_name' => $po->project?->name ?? 'No Project',
                    'client_name' => $po->project?->client?->name ?? 'Unknown',
                    'total' => $total,
                    'total_formatted' => number_format($total, 2),
                    'spent' => $spent,
                    'spent_formatted' => number_format($spent, 2),
                    'remaining' => $remaining,
                    'remaining_formatted' => number_format($remaining, 2),
                    'utilization' => round($utilization, 1),
                    'is_over_budget' => $spent > $total,
                ];
            })
            ->filter(function ($po) {
                return $po['remaining'] > 0; // Only show POs with remaining budget
            })
            ->sortBy('remaining')
            ->reverse()
            ->take(10)
            ->values();

        return [
            'purchase_orders' => $purchaseOrders,
            'count' => $purchaseOrders->count(),
            'total_remaining' => $purchaseOrders->sum('remaining'),
            'total_remaining_formatted' => number_format($purchaseOrders->sum('remaining'), 2),
        ];
    }

    /**
     * Widget: Unbilled Time Entries
     */
    public function getUnbilledTimeWidget(): array
    {
        $entries = TimeEntry::with(['client', 'project.client'])
            ->where('billable', true)
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->whereDoesntHave('invoiceItem')
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'project_name' => $entry->project?->name ?? 'No Project',
                    'client_name' => $entry->client?->name
                        ?? $entry->project?->client?->name
                        ?? 'Unknown',
                    'description' => $entry->description,
                    'hours' => $entry->hours,
                    'rate' => $entry->rate ?? $entry->project?->hourly_rate ?? 0,
                    'amount' => $entry->hours * ($entry->rate ?? $entry->project?->hourly_rate ?? 0),
                    'date' => $entry->entry_date?->format('Y-m-d'),
                ];
            })
            ->filter(function ($entry) {
                return $entry['hours'] > 0;
            })
            ->sortByDesc('date')
            ->take(20)
            ->values();

        $totalHours = $entries->sum('hours');
        $totalAmount = $entries->sum('amount');

        return [
            'entries' => $entries,
            'count' => $entries->count(),
            'total_hours' => round($totalHours, 2),
            'total_amount' => $totalAmount,
            'total_amount_formatted' => number_format($totalAmount, 2),
        ];
    }

    /**
     * Widget: Bank Balance + Unreconciled Count
     */
    public function getBankBalanceWidget(): array
    {
        $bankTransactions = BankTransaction::all();

        $totalCredits = $bankTransactions->where('type', BankTransaction::TYPE_CREDIT)->sum('amount');
        $totalDebits = $bankTransactions->where('type', BankTransaction::TYPE_DEBIT)->sum('amount');
        $balance = $totalCredits - $totalDebits;

        $unreconciled = BankTransaction::where('status', BankTransaction::STATUS_PENDING)->count();
        $matched = BankTransaction::where('status', BankTransaction::STATUS_MATCHED)->count();
        $ignored = BankTransaction::where('status', BankTransaction::STATUS_IGNORED)->count();

        // Group by source
        $bySource = $bankTransactions
            ->groupBy('source')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total' => $group->sum('amount'),
            ]);

        return [
            'balance' => $balance,
            'balance_formatted' => number_format($balance, 2),
            'total_credits' => $totalCredits,
            'total_credits_formatted' => number_format($totalCredits, 2),
            'total_debits' => $totalDebits,
            'total_debits_formatted' => number_format($totalDebits, 2),
            'unreconciled_count' => $unreconciled,
            'matched_count' => $matched,
            'ignored_count' => $ignored,
            'by_source' => $bySource,
        ];
    }

    /**
     * Widget: Monthly P&L Trend
     */
    public function getPnLTrendWidget(int $months = 12): array
    {
        $data = [];
        $today = Carbon::now();

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = $today->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            // Revenue (payments received)
            $revenue = Payment::where('status', Payment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount');

            // Expenses (supplier payments made)
            $expenses = BillPayment::where('status', BillPayment::STATUS_COMPLETED)
                ->whereBetween('payment_date', [$monthStart, $monthEnd])
                ->sum('amount');

            // Invoices issued
            $invoicesIssued = Invoice::whereBetween('issue_date', [$monthStart, $monthEnd])
                ->sum('total');

            // Outstanding invoices
            $outstanding = Invoice::whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ])->sum('amount_due');

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

        // Calculate trends
        $avgRevenue = collect($data)->avg('revenue');
        $avgExpenses = collect($data)->avg('expenses');
        $avgNetIncome = collect($data)->avg('net_income');

        return [
            'months' => $data,
            'avg_revenue' => round($avgRevenue, 2),
            'avg_expenses' => round($avgExpenses, 2),
            'avg_net_income' => round($avgNetIncome, 2),
            'total_revenue' => collect($data)->sum('revenue'),
            'total_expenses' => collect($data)->sum('expenses'),
            'total_net_income' => collect($data)->sum('net_income'),
        ];
    }
}

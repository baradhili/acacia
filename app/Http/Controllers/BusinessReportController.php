<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Prepayment;
use App\Services\PrepaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Business Reports — views over the core AR/AP documents: income by
 * customer, expenses by category, AR/AP aging and the prepayment
 * amortisation schedule.
 */
class BusinessReportController extends Controller
{
    public function incomeByCustomer(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $clientId = $request->get('client_id');

        $query = Invoice::with(['client', 'allocations'])
            ->whereBetween('issue_date', [$startDate, $endDate])
            ->where('status', '!=', 'cancelled');

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $invoices = $query->get();

        $byCustomer = $invoices->groupBy('client_id')
            ->map(function ($invoices, $clientId) {
                $client = $invoices->first()->client;

                return [
                    'client' => $client,
                    'invoice_count' => $invoices->count(),
                    'total_invoiced' => $invoices->sum('total'),
                    // Sum the amount allocated to each invoice (not the full
                    // payment amount — a payment split across invoices would
                    // otherwise be double-counted).
                    'total_paid' => $invoices->sum(function ($inv) {
                        return $inv->allocations->sum('amount');
                    }),
                    // Outstanding = total less allocations, floored at zero
                    'outstanding' => $invoices->sum(function ($inv) {
                        return max(0, (float) $inv->total - $inv->allocations->sum('amount'));
                    }),
                ];
            })->sortByDesc('total_invoiced');

        $clients = Client::orderBy('name')->pluck('name', 'id');

        $totalInvoiced = $byCustomer->sum('total_invoiced');
        $totalPaid = $byCustomer->sum('total_paid');
        $totalOutstanding = $byCustomer->sum('outstanding');

        return view('reports.income-by-customer', compact(
            'byCustomer', 'clients', 'startDate', 'endDate', 'clientId',
            'totalInvoiced', 'totalPaid', 'totalOutstanding'
        ));
    }

    public function expensesByCategory(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get('account_id');

        // Group paid-bill line items by their IFRS expense account, so the
        // report aligns with the chart of accounts (and the journals).
        $query = BillItem::query()
            ->join('bills', 'bills.id', '=', 'bill_items.bill_id')
            ->join('ifrs_accounts', 'ifrs_accounts.id', '=', 'bill_items.expense_account_id')
            ->whereBetween('bills.bill_date', [$startDate, $endDate])
            ->whereIn('bills.status', [Bill::STATUS_OPEN, Bill::STATUS_PARTIALLY_PAID, Bill::STATUS_PAID, Bill::STATUS_OVERDUE]);

        if ($accountId) {
            $query->where('bill_items.expense_account_id', $accountId);
        }

        $byCategory = $query->groupBy('ifrs_accounts.id', 'ifrs_accounts.code', 'ifrs_accounts.name')
            ->orderBy('ifrs_accounts.code')
            ->get([
                'ifrs_accounts.id as account_id',
                'ifrs_accounts.code as account_code',
                'ifrs_accounts.name as account_name',
                DB::raw('COUNT(*) as expense_count'),
                DB::raw('SUM(bill_items.total - bill_items.tax_amount) as total_amount'),
                DB::raw('SUM(bill_items.tax_amount) as total_tax'),
                DB::raw('SUM(bill_items.total) as total'),
            ]);

        $categories = Bill::expenseAccounts();

        $totalAmount = $byCategory->sum('total_amount');
        $totalTax = $byCategory->sum('total_tax');
        $total = $byCategory->sum('total');

        return view('reports.expenses-by-category', compact(
            'byCategory', 'categories', 'startDate', 'endDate', 'accountId',
            'totalAmount', 'totalTax', 'total'
        ));
    }

    public function agingReport(Request $request)
    {
        $asOfDate = $request->get('as_of_date')
            ? Carbon::parse($request->get('as_of_date'))->endOfDay()
            : Carbon::now()->endOfDay();

        $type = $request->get('type', 'ar'); // ar or ap

        // Outstanding balance is total less allocations (amount_due) — there
        // is no `balance` column to filter on, so eager-load allocations and
        // filter in PHP.
        if ($type === 'ap') {
            $partyLabel = 'Supplier';
            $documents = Bill::with(['supplier', 'allocations'])
                ->where('status', '!=', Bill::STATUS_CANCELLED)
                ->get();
        } else {
            $partyLabel = 'Client';
            $documents = Invoice::with(['client', 'allocations'])
                ->where('status', '!=', 'cancelled')
                ->get();
        }

        $documents = $documents->filter(function ($document) use ($asOfDate) {
            return $document->amount_due > 0
                && $document->due_date
                && Carbon::parse($document->due_date)->lte($asOfDate);
        });

        // Group by aging buckets
        $buckets = [
            'current' => ['label' => 'Current', 'min' => 0, 'max' => 0, 'invoices' => []],
            'days_1_30' => ['label' => '1-30 Days', 'min' => 1, 'max' => 30, 'invoices' => []],
            'days_31_60' => ['label' => '31-60 Days', 'min' => 31, 'max' => 60, 'invoices' => []],
            'days_61_90' => ['label' => '61-90 Days', 'min' => 61, 'max' => 90, 'invoices' => []],
            'days_over_90' => ['label' => 'Over 90 Days', 'min' => 91, 'max' => null, 'invoices' => []],
        ];

        foreach ($documents as $document) {
            // Whole calendar days late: both dates start-of-day, so a
            // document due earlier on the as-of day (the as-of date is
            // end-of-day) stays in the Current bucket instead of the
            // fractional day difference tipping it into 1-30.
            $daysPastDue = (int) Carbon::parse($document->due_date)->startOfDay()
                ->diffInDays($asOfDate->copy()->startOfDay());

            if ($daysPastDue <= 0) {
                $bucket = &$buckets['current'];
            } elseif ($daysPastDue <= 30) {
                $bucket = &$buckets['days_1_30'];
            } elseif ($daysPastDue <= 60) {
                $bucket = &$buckets['days_31_60'];
            } elseif ($daysPastDue <= 90) {
                $bucket = &$buckets['days_61_90'];
            } else {
                $bucket = &$buckets['days_over_90'];
            }

            $bucket['invoices'][] = [
                'invoice' => $document,
                'party' => $type === 'ap' ? $document->supplier : $document->client,
                'party_id' => $document->{$type === 'ap' ? 'supplier_id' : 'client_id'},
                'amount' => $document->amount_due,
                'days_past_due' => $daysPastDue,
            ];
        }

        // Calculate totals
        foreach ($buckets as &$bucket) {
            $bucket['total'] = collect($bucket['invoices'])->sum('amount');
        }

        $grandTotal = collect($buckets)->sum('total');

        return view('reports.aging', compact(
            'buckets', 'asOfDate', 'type', 'grandTotal', 'partyLabel'
        ));
    }

    /**
     * Prepaid Subscriptions — Amortisation Schedule: every schedule with
     * its posted, reversed and planned months, plus entity totals for
     * year-end prepaid-asset review (task spec §3.4).
     */
    public function prepaymentSchedule(Request $request)
    {
        $prepayments = Prepayment::with(['assetAccount', 'expenseAccount', 'billPayment', 'billItem.bill'])
            ->orderBy('service_start')
            ->get();

        $schedules = $prepayments->mapWithKeys(fn ($p) => [$p->id => PrepaymentService::scheduleWithPlanned($p)]);

        $totals = [
            'funded' => round($prepayments->where('status', '!=', Prepayment::STATUS_VOID)->sum('total_amount'), 2),
            'amortised' => round($prepayments->sum(fn ($p) => $p->amortisedAmount()), 2),
            'remaining' => round($prepayments->where('status', '!=', Prepayment::STATUS_VOID)->sum(fn ($p) => $p->remainingAmount()), 2),
        ];

        return view('reports.prepayment-schedule', compact('prepayments', 'schedules', 'totals'));
    }

    /**
     * Export Prepayment Amortisation Schedule to PDF
     */
    public function exportPrepaymentSchedulePdf(Request $request)
    {
        $prepayments = Prepayment::with(['assetAccount', 'expenseAccount', 'billPayment', 'billItem.bill'])
            ->orderBy('service_start')
            ->get();

        $schedules = $prepayments->mapWithKeys(fn ($p) => [$p->id => PrepaymentService::scheduleWithPlanned($p)]);

        $totals = [
            'funded' => round($prepayments->where('status', '!=', Prepayment::STATUS_VOID)->sum('total_amount'), 2),
            'amortised' => round($prepayments->sum(fn ($p) => $p->amortisedAmount()), 2),
            'remaining' => round($prepayments->where('status', '!=', Prepayment::STATUS_VOID)->sum(fn ($p) => $p->remainingAmount()), 2),
        ];

        $pdf = Pdf::loadView('reports.pdf.prepayment-schedule', compact('prepayments', 'schedules', 'totals'));

        return $pdf->download('Prepayment_Amortisation_Schedule.pdf');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Currency;
use IFRS\Models\ReportingPeriod;
use IFRS\Reports\IncomeStatement;
use IFRS\Reports\BalanceSheet;
use IFRS\Reports\TrialBalance;
use IFRS\Reports\CashFlowStatement;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Get the current or create a reporting period
     */
    protected function getReportingPeriod($date = null): ReportingPeriod
    {
        $date = $date ?? Carbon::now();
        return ReportingPeriod::where('period_status', ReportingPeriod::STATUS_OPEN)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first() ?? ReportingPeriod::create([
                'year' => $date->year,
                'period' => $date->month,
                'start_date' => $date->copy()->startOfMonth(),
                'end_date' => $date->copy()->endOfMonth(),
                'period_status' => ReportingPeriod::STATUS_OPEN,
            ]);
    }

    /**
     * Time Reports
     */
    public function timeByClient(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $clientId = $request->get('client_id');

        $query = TimeEntry::with(['project.client', 'user'])
            ->whereBetween('start_time', [$startDate, $endDate])
            ->approved();

        if ($clientId) {
            $query->whereHas('project', fn($q) => $q->where('client_id', $clientId));
        }

        $timeEntries = $query->get();

        // Group by client
        $byClient = $timeEntries->groupBy(fn($e) => $e->project?->client?->id ?? 'unassigned')
            ->map(function ($entries, $clientId) {
                $client = $entries->first()->project?->client?->name ?? 'Unassigned';
                return [
                    'client' => $client,
                    'total_hours' => $entries->sum('hours'),
                    'total_amount' => $entries->sum('total'),
                    'billable_hours' => $entries->where('billable', true)->sum('hours'),
                    'entry_count' => $entries->count(),
                ];
            })->sortByDesc('total_hours');

        $clients = Client::orderBy('name')->pluck('name', 'id');

        $totalHours = $byClient->sum('total_hours');
        $totalAmount = $byClient->sum('total_amount');

        return view('reports.time-by-client', compact(
            'byClient', 'clients', 'startDate', 'endDate', 'clientId',
            'totalHours', 'totalAmount'
        ));
    }

    public function timeByStaff(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $userId = $request->get('user_id');

        $query = TimeEntry::with(['user', 'project'])
            ->whereBetween('start_time', [$startDate, $endDate])
            ->approved();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $timeEntries = $query->get();

        // Group by staff
        $byStaff = $timeEntries->groupBy('user_id')
            ->map(function ($entries, $userId) {
                $user = $entries->first()->user;
                return [
                    'user' => $user,
                    'total_hours' => $entries->sum('hours'),
                    'total_amount' => $entries->sum('total'),
                    'billable_hours' => $entries->where('billable', true)->sum('hours'),
                    'non_billable_hours' => $entries->where('billable', false)->sum('hours'),
                    'entry_count' => $entries->count(),
                ];
            })->sortByDesc('total_hours');

        $staff = User::orderBy('name')->pluck('name', 'id');

        $totalHours = $byStaff->sum('total_hours');
        $totalAmount = $byStaff->sum('total_amount');

        return view('reports.time-by-staff', compact(
            'byStaff', 'staff', 'startDate', 'endDate', 'userId',
            'totalHours', 'totalAmount'
        ));
    }

    public function timeByProject(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $projectId = $request->get('project_id');

        $query = TimeEntry::with(['project.client', 'user'])
            ->whereBetween('start_time', [$startDate, $endDate])
            ->approved();

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $timeEntries = $query->get();

        // Group by project
        $byProject = $timeEntries->groupBy('project_id')
            ->map(function ($entries, $projectId) {
                $project = $entries->first()->project;
                return [
                    'project' => $project,
                    'client' => $project?->client?->name ?? 'N/A',
                    'total_hours' => $entries->sum('hours'),
                    'total_amount' => $entries->sum('total'),
                    'billable_hours' => $entries->where('billable', true)->sum('hours'),
                    'non_billable_hours' => $entries->where('billable', false)->sum('hours'),
                    'entry_count' => $entries->count(),
                    'budget_hours' => $project?->budget_hours,
                    'utilization' => $project?->budget_hours 
                        ? round(($entries->sum('hours') / $project->budget_hours) * 100, 1) 
                        : null,
                ];
            })->sortByDesc('total_hours');

        $projects = Project::orderBy('name')->pluck('name', 'id');

        $totalHours = $byProject->sum('total_hours');
        $totalAmount = $byProject->sum('total_amount');

        return view('reports.time-by-project', compact(
            'byProject', 'projects', 'startDate', 'endDate', 'projectId',
            'totalHours', 'totalAmount'
        ));
    }

    /**
     * Financial Reports
     */
    public function trialBalance(Request $request)
    {
        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $this->getReportingPeriod($endDate);
        
        $trialBalance = new TrialBalance();
        $trialBalance->before($endDate);
        
        $accounts = Account::where('account_type', '!=', Account::TYPE_HEADER)->get();
        
        $debitTotal = 0;
        $creditTotal = 0;
        
        $accountLines = collect();
        foreach ($trialBalance->getData()['accounts'] as $item) {
            $account = Account::find($item['account']['id']);
            if ($account && $account->account_type !== Account::TYPE_HEADER) {
                $balance = $item['balance'] ?? 0;
                $isDebit = in_array($account->account_type, [
                    Account::ASSET, Account::EXPENSE, Account::DIRECT_COSTS
                ]);
                
                $debitBalance = $isDebit ? abs($balance) : 0;
                $creditBalance = !$isDebit ? abs($balance) : 0;
                
                $debitTotal += $debitBalance;
                $creditTotal += $creditBalance;
                
                $accountLines->push([
                    'account' => $account,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->account_type,
                    'debit' => $debitBalance,
                    'credit' => $creditBalance,
                ]);
            }
        }
        
        return view('reports.trial-balance', compact(
            'accountLines', 'endDate', 'debitTotal', 'creditTotal'
        ));
    }

    public function incomeStatement(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $this->getReportingPeriod($endDate);
        
        $incomeStatement = new IncomeStatement($startDate, $endDate);
        
        $lines = $incomeStatement->getData();
        
        return view('reports.income-statement', compact(
            'lines', 'startDate', 'endDate'
        ));
    }

    public function balanceSheet(Request $request)
    {
        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $this->getReportingPeriod($endDate);
        
        $balanceSheet = new BalanceSheet($endDate);
        
        $lines = $balanceSheet->getData();
        
        return view('reports.balance-sheet', compact(
            'lines', 'endDate'
        ));
    }

    public function cashFlowStatement(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $this->getReportingPeriod($endDate);
        
        $cashFlow = new CashFlowStatement($startDate, $endDate);
        
        $lines = $cashFlow->getData();
        
        return view('reports.cash-flow', compact(
            'lines', 'startDate', 'endDate'
        ));
    }

    /**
     * Business Reports
     */
    public function incomeByCustomer(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $clientId = $request->get('client_id');

        $query = \App\Models\Invoice::with(['client', 'payments'])
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
                    'total_paid' => $invoices->sum(function ($inv) {
                        return $inv->payments->sum('amount');
                    }),
                    'outstanding' => $invoices->sum('balance'),
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

        $category = $request->get('category');

        $query = Expense::with(['supplier'])
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->where('status', '!=', 'cancelled');

        if ($category) {
            $query->where('category', $category);
        }

        $expenses = $query->get();

        $byCategory = $expenses->groupBy('category')
            ->map(function ($expenses, $cat) {
                return [
                    'category' => ucwords(str_replace('_', ' ', $cat)),
                    'category_key' => $cat,
                    'expense_count' => $expenses->count(),
                    'total_amount' => $expenses->sum('amount'),
                    'total_tax' => $expenses->sum('tax_amount'),
                    'total' => $expenses->sum('total'),
                ];
            })->sortByDesc('total_amount');

        $categories = Expense::CATEGORIES;

        $totalAmount = $byCategory->sum('total_amount');
        $totalTax = $byCategory->sum('total_tax');
        $total = $byCategory->sum('total');

        return view('reports.expenses-by-category', compact(
            'byCategory', 'categories', 'startDate', 'endDate', 'category',
            'totalAmount', 'totalTax', 'total'
        ));
    }

    public function agingReport(Request $request)
    {
        $asOfDate = $request->get('as_of_date')
            ? Carbon::parse($request->get('as_of_date'))->endOfDay()
            : Carbon::now()->endOfDay();

        $type = $request->get('type', 'ar'); // ar or ap

        $invoices = \App\Models\Invoice::with(['client'])
            ->where('status', '!=', 'cancelled')
            ->where('balance', '>', 0)
            ->get()
            ->filter(function ($invoice) use ($asOfDate) {
                return $invoice->due_date && Carbon::parse($invoice->due_date)->lte($asOfDate);
            });

        // Group by aging buckets
        $buckets = [
            'current' => ['label' => 'Current', 'min' => 0, 'max' => 0, 'invoices' => []],
            'days_1_30' => ['label' => '1-30 Days', 'min' => 1, 'max' => 30, 'invoices' => []],
            'days_31_60' => ['label' => '31-60 Days', 'min' => 31, 'max' => 60, 'invoices' => []],
            'days_61_90' => ['label' => '61-90 Days', 'min' => 61, 'max' => 90, 'invoices' => []],
            'days_over_90' => ['label' => 'Over 90 Days', 'min' => 91, 'max' => null, 'invoices' => []],
        ];

        foreach ($invoices as $invoice) {
            $daysPastDue = Carbon::parse($invoice->due_date)->diffInDays($asOfDate);
            
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
                'invoice' => $invoice,
                'client' => $invoice->client,
                'amount' => $invoice->balance,
                'days_past_due' => $daysPastDue,
            ];
        }

        // Calculate totals
        foreach ($buckets as &$bucket) {
            $bucket['total'] = collect($bucket['invoices'])->sum('amount');
        }

        $grandTotal = collect($buckets)->sum('total');

        return view('reports.aging', compact(
            'buckets', 'asOfDate', 'type', 'grandTotal'
        ));
    }

    public function gstReport(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        // Get GST collected from invoices (output tax)
        $invoices = \App\Models\Invoice::whereBetween('issue_date', [$startDate, $endDate])
            ->where('status', '!=', 'cancelled')
            ->get();
        
        $gstCollected = $invoices->sum('tax_amount');
        $totalInvoices = $invoices->sum('subtotal');

        // Get GST paid from expenses (input tax)
        $expenses = Expense::whereBetween('expense_date', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'approved'])
            ->get();
        
        $gstPaid = $expenses->sum('tax_amount');
        $totalExpenses = $expenses->sum('amount');

        $netGst = $gstCollected - $gstPaid;

        return view('reports.gst', compact(
            'startDate', 'endDate',
            'gstCollected', 'totalInvoices',
            'gstPaid', 'totalExpenses',
            'netGst'
        ));
    }
}

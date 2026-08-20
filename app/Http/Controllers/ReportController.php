<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Currency;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Reports\IncomeStatement;
use IFRS\Reports\CashFlowStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Ensure reporting periods exist for the financial year containing
     * $date AND the FY before it. The package derives FY boundaries from
     * the entity's year_start (July for Australia: 1 Jul – 30 Jun), but
     * Account::openingBalance($year) internally resolves its period via
     * "{year}-01-01" — which with year_start = 7 lands in the PREVIOUS
     * financial year — so the package's statement helpers also need the
     * prior-FY period row to exist. Mirrors IFRSSeeder's period shape.
     */
    protected function getReportingPeriod($date = null): ReportingPeriod
    {
        $date = Carbon::parse($date ?? now());
        $entity = $this->ifrsEntity();
        $year = ReportingPeriod::year($date, $entity);

        $period = ReportingPeriod::firstOrCreate(
            ['entity_id' => $entity->id, 'calendar_year' => $year],
            ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
        );

        if ($year - 1 >= 1) {
            ReportingPeriod::firstOrCreate(
                ['entity_id' => $entity->id, 'calendar_year' => $year - 1],
                ['period_count' => 1, 'status' => ReportingPeriod::OPEN],
            );
        }

        return $period;
    }

    /**
     * The IFRS entity of the authenticated user (falling back to the first
     * entity) — most package helpers need it explicitly in background
     * contexts where no user is logged in.
     */
    protected function ifrsEntity(): ?Entity
    {
        return Auth::user()?->entity ?? Entity::first();
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
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
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
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
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
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
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
    /**
     * Row builder for the financial statements: one row per account of the
     * given types, with the account's balance for the statement period
     * (movement for P&L sections, cumulative-to-date balance for
     * balance-sheet sections). Balances are magnitudes — the views style
     * signs per section.
     */
    protected function statementAccountRows(array $accountTypes, Carbon $startDate, Carbon $endDate, bool $closing = false): array
    {
        $entity = $this->ifrsEntity();
        $rows = [];

        foreach (Account::where('entity_id', $entity->id)
            ->whereIn('account_type', $accountTypes)
            ->orderBy('code')
            ->get() as $account
        ) {
            // Cumulative from an arbitrary epoch: exact as-at balances that
            // don't depend on year-end closing entries having been posted
            // (the package's period-scoped closingBalance() does).
            $balance = (float) Ledger::balance(
                $account,
                $closing ? Carbon::create(2000, 1, 1) : $startDate,
                $endDate,
                $entity->currency_id
            )[$entity->currency_id];

            if (abs($balance) < 0.005) {
                continue;
            }

            $rows[] = [
                'account' => ['name' => $account->name, 'code' => $account->code],
                'balance' => round(abs($balance), 2),
            ];
        }

        return $rows;
    }

    public function trialBalance(Request $request)
    {
        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $this->getReportingPeriod($endDate);
        $entity = $this->ifrsEntity();

        // Cumulative ledger balances as at $endDate: a positive balance is
        // debit-normal, negative is credit-normal.
        $debitTotal = 0;
        $creditTotal = 0;
        $accountLines = collect();

        foreach (Account::where('entity_id', $entity->id)->orderBy('code')->get() as $account) {
            $balance = (float) Ledger::balance(
                $account,
                Carbon::create(2000, 1, 1),
                $endDate,
                $entity->currency_id
            )[$entity->currency_id];

            if (abs($balance) < 0.005) {
                continue;
            }

            $debitBalance = $balance > 0 ? abs($balance) : 0;
            $creditBalance = $balance < 0 ? abs($balance) : 0;

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

        return view('reports.trial-balance', compact(
            'accountLines', 'endDate', 'debitTotal', 'creditTotal'
        ));
    }

    public function incomeStatement(Request $request)
    {
        $entity = $this->ifrsEntity();
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            // Default to the start of the financial year (1 July in AU)
            : ReportingPeriod::periodStart(now(), $entity);

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $this->getReportingPeriod($endDate);

        // Authoritative totals from the package; detail rows per account
        $statement = new IncomeStatement($startDate->toDateString(), $endDate->toDateString(), $entity);
        $sections = $statement->getSections();

        $revenue = $this->statementAccountRows(
            [Account::OPERATING_REVENUE, Account::NON_OPERATING_REVENUE],
            $startDate, $endDate
        );
        $directCosts = $this->statementAccountRows(
            [Account::DIRECT_EXPENSE],
            $startDate, $endDate
        );
        $expenses = $this->statementAccountRows(
            [Account::OPERATING_EXPENSE, Account::OVERHEAD_EXPENSE, Account::OTHER_EXPENSE],
            $startDate, $endDate
        );

        $lines = ['statement' => [
            'revenue' => $revenue,
            'revenueTotal' => (float) $sections['results'][IncomeStatement::TOTAL_REVENUE],
            'direct_costs' => $directCosts,
            'directCostsTotal' => array_sum(array_column($directCosts, 'balance')),
            'grossProfit' => (float) $sections['results'][IncomeStatement::GROSS_PROFIT],
            'expense' => $expenses,
            'expenseTotal' => array_sum(array_column($expenses, 'balance')),
            'netProfit' => (float) $sections['results'][IncomeStatement::NET_PROFIT],
        ]];

        return view('reports.income-statement', compact(
            'lines', 'startDate', 'endDate'
        ));
    }

    public function balanceSheet(Request $request)
    {
        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $entity = $this->ifrsEntity();
        $this->getReportingPeriod($endDate);

        // Balance-sheet sections report cumulative (as-at) balances
        $fyStart = ReportingPeriod::periodStart($endDate, $entity);
        $assets = $this->statementAccountRows(
            [Account::NON_CURRENT_ASSET, Account::INVENTORY, Account::BANK, Account::CURRENT_ASSET, Account::RECEIVABLE],
            $fyStart, $endDate, true
        );
        $liabilities = $this->statementAccountRows(
            [Account::NON_CURRENT_LIABILITY, Account::CURRENT_LIABILITY, Account::PAYABLE, Account::CONTROL],
            $fyStart, $endDate, true
        );
        $equity = $this->statementAccountRows(
            [Account::EQUITY],
            $fyStart, $endDate, true
        );

        // The period's profit adds to equity before it is closed
        $incomeStatement = new IncomeStatement($fyStart->toDateString(), $endDate->toDateString(), $entity);
        $netProfit = (float) $incomeStatement->getSections()['results'][IncomeStatement::NET_PROFIT];

        $lines = ['statement' => [
            'assets' => $assets,
            'assetsTotal' => round(array_sum(array_column($assets, 'balance')), 2),
            'liabilities' => $liabilities,
            'liabilitiesTotal' => round(array_sum(array_column($liabilities, 'balance')), 2),
            'equity' => $equity,
            'equityTotal' => round(array_sum(array_column($equity, 'balance')) + $netProfit, 2),
        ]];

        return view('reports.balance-sheet', compact(
            'lines', 'endDate'
        ));
    }

    public function cashFlowStatement(Request $request)
    {
        $entity = $this->ifrsEntity();
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            // Default to the start of the financial year (1 July in AU)
            : ReportingPeriod::periodStart(now(), $entity);

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $this->getReportingPeriod($endDate);

        $statement = new CashFlowStatement($startDate->toDateString(), $endDate->toDateString(), $entity);
        $sections = $statement->getSections();

        // The package derives cash flows from balance movements, not
        // per-account lines; present the components it does expose.
        $profit = (float) $sections['balances'][CashFlowStatement::PROFIT];
        $operatingTotal = (float) $sections['results'][CashFlowStatement::OPERATIONS_CASH_FLOW];
        $investingTotal = (float) $sections['results'][CashFlowStatement::INVESTMENT_CASH_FLOW];
        $financingTotal = (float) $sections['results'][CashFlowStatement::FINANCING_CASH_FLOW];
        $netCash = (float) $sections['balances'][CashFlowStatement::NET_CASH_FLOW];

        $lines = ['statement' => [
            'operating' => [
                ['account' => ['name' => 'Net profit for the period'], 'balance' => round(abs($profit), 2)],
                ['account' => ['name' => 'Working capital & other operating movements'], 'balance' => round(abs($operatingTotal - $profit), 2)],
            ],
            'operatingTotal' => round(abs($operatingTotal), 2),
            'investing' => [
                ['account' => ['name' => 'Non-current asset movements'], 'balance' => round(abs($investingTotal), 2)],
            ],
            'investingTotal' => round(abs($investingTotal), 2),
            'financing' => [
                ['account' => ['name' => 'Financing — loans & equity movements'], 'balance' => round(abs($financingTotal), 2)],
            ],
            'financingTotal' => round(abs($financingTotal), 2),
            'netCash' => round($netCash, 2),
        ]];

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

        $query = \App\Models\Invoice::with(['client', 'allocations'])
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
            $documents = \App\Models\Invoice::with(['client', 'allocations'])
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
            $daysPastDue = Carbon::parse($document->due_date)->diffInDays($asOfDate);

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

        // Get GST paid from bills (input tax) — per-line GST treatment means
        // GST-free lines simply contribute no tax_amount.
        $bills = Bill::whereBetween('bill_date', [$startDate, $endDate])
            ->whereNotIn('status', [Bill::STATUS_DRAFT, Bill::STATUS_CANCELLED])
            ->get();

        $gstPaid = $bills->sum('tax_amount');
        $totalExpenses = $bills->sum('subtotal');

        $netGst = $gstCollected - $gstPaid;

        return view('reports.gst', compact(
            'startDate', 'endDate',
            'gstCollected', 'totalInvoices',
            'gstPaid', 'totalExpenses',
            'netGst'
        ));
    }


    /**
     * Build an account statement from the ledger (v6 schema: post_account,
     * posting_date, entry_type D/C + amount; narration/reference live on
     * the transaction). The opening balance is cumulative — FY opening
     * balances plus everything posted from the FY start up to the day
     * before the statement period starts — sign-normalised to the
     * account's normal side.
     */
    protected function buildAccountStatement(Account $account, Carbon $startDate, Carbon $endDate): array
    {
        $entity = $account->entity;

        // Debit-normal account types; everything else (liabilities, equity,
        // revenue, contra-assets) is credit-normal.
        $isDebitNormal = in_array($account->account_type, [
            Account::NON_CURRENT_ASSET, Account::INVENTORY, Account::BANK,
            Account::CURRENT_ASSET, Account::RECEIVABLE,
            Account::OPERATING_EXPENSE, Account::DIRECT_EXPENSE,
            Account::OVERHEAD_EXPENSE, Account::OTHER_EXPENSE,
        ]);

        // Cumulative opening balance: everything posted before the period
        // starts (the balances table only carries FY opening entries after
        // a year-end close, so the ledger is the source of truth here).
        $opening = (float) Ledger::balance(
            $account,
            Carbon::create(2000, 1, 1),
            $startDate->copy()->subSecond(),
            $entity->currency_id
        )[$entity->currency_id];
        $openingBalance = $isDebitNormal ? $opening : -$opening;

        $entries = Ledger::where('post_account', $account->id)
            ->whereBetween('posting_date', [$startDate, $endDate])
            ->with('transaction')
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();

        $runningBalance = $openingBalance;
        $transactions = collect();

        foreach ($entries as $entry) {
            $isDebit = $entry->entry_type === Balance::DEBIT;
            $debit = $isDebit ? (float) $entry->amount : 0.0;
            $credit = $isDebit ? 0.0 : (float) $entry->amount;

            $runningBalance += $isDebitNormal ? $debit - $credit : $credit - $debit;

            $transaction = $entry->transaction;
            $transactions->push([
                // The vendor Transaction model does not cast the date, so
                // wrap it for the view's ->format() calls
                'date' => Carbon::parse($transaction->transaction_date ?? $entry->posting_date),
                'transaction_id' => $entry->transaction_id,
                'transaction_type' => config('ifrs.transactions')[$transaction->transaction_type ?? ''] ?? $transaction->transaction_type ?? '',
                'narration' => $transaction->narration ?? '',
                'reference' => $transaction->reference ?? '',
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
            ]);
        }

        return [
            'account' => $account,
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'total_debit' => $transactions->sum('debit'),
            'total_credit' => $transactions->sum('credit'),
            'transaction_count' => $transactions->count(),
            'transactions' => $transactions,
        ];
    }

    /**
     * IFRS Account Statement Report
     */
    public function accountStatement(Request $request)
    {
        $startDate = $request->get("start_date")
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get("end_date")
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get("account_id");

        $accounts = Account::orderBy("code")
            ->get(["id", "code", "name", "account_type"]);

        $statementData = null;

        if ($accountId) {
            $this->getReportingPeriod($endDate);
            $statementData = $this->buildAccountStatement(Account::find($accountId), $startDate, $endDate);
        }

        return view("reports.account-statement", compact(
            "statementData", "accounts", "startDate", "endDate", "accountId"
        ));
    }

    /**
     * IFRS Account Schedule Report
     */
    public function accountSchedule(Request $request)
    {
        $startDate = $request->get("start_date")
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get("end_date")
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get("account_id");

        $accounts = Account::orderBy("code")
            ->get(["id", "code", "name", "account_type"]);

        $scheduleData = null;

        if ($accountId) {
            $account = Account::find($accountId);

            // Get all journal entries with line items for this account in date range.
            // NOTE: the IFRS Transaction date column is `transaction_date`
            // (not `date`), and debit/credit is determined by the line item's
            // `credited` boolean (false = debit, true = credit) — there is no
            // `type` column and `LineItem::DEBIT`/`::CREDIT` do not exist.
            $lineItems = LineItem::where("account_id", $accountId)
                ->whereHas("transaction", function ($query) use ($startDate, $endDate) {
                    $query->whereBetween("transaction_date", [$startDate, $endDate]);
                })
                ->with(["transaction", "transaction.lineItems"])
                ->get();

            // Group by transaction (sorting by a related column in SQL would
            // need a join; sort the grouped collection instead)
            $groupedByTransaction = $lineItems->groupBy("transaction_id")
                ->sortBy(fn ($items) => $items->first()->transaction->transaction_date);

            $scheduleLines = collect();
            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($groupedByTransaction as $transactionId => $items) {
                $transaction = $items->first()->transaction;

                // Get all line items for this transaction
                $allItems = $transaction->lineItems ?? collect();

                // credited=false -> debit, credited=true -> credit
                $debitTotal = $allItems->where("credited", false)->sum("amount");
                $creditTotal = $allItems->where("credited", true)->sum("amount");

                $totalDebit += $debitTotal;
                $totalCredit += $creditTotal;

                $scheduleLines->push([
                    "date" => Carbon::parse($transaction->transaction_date),
                    "transaction_id" => $transactionId,
                    "transaction_type" => class_basename($transaction),
                    "narration" => $transaction->narration ?? "",
                    "reference" => $transaction->reference ?? "",
                    "line_items" => $allItems,
                    "debit" => $debitTotal,
                    "credit" => $creditTotal,
                ]);
            }

            $scheduleData = [
                "account" => $account,
                "start_date" => $startDate,
                "end_date" => $endDate,
                "total_debit" => $totalDebit,
                "total_credit" => $totalCredit,
                "line_count" => $scheduleLines->count(),
                "lines" => $scheduleLines,
            ];
        }

        return view("reports.account-schedule", compact(
            "scheduleData", "accounts", "startDate", "endDate", "accountId"
        ));
    }

    /**
     * Tax Summary Report
     */
    public function taxSummary(Request $request)
    {
        $startDate = $request->get("start_date")
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get("end_date")
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        // Get invoices with tax by rate
        $invoices = \App\Models\Invoice::whereBetween("issue_date", [$startDate, $endDate])
            ->where("status", "!=", "cancelled")
            ->with("items")
            ->get();

        $salesByTaxRate = $invoices->flatMap(function ($invoice) {
            return $invoice->items->map(function ($item) use ($invoice) {
                // Use the stored line amounts: tax_amount is authoritative
                // (recomputing qty x price x rate ignores discounts), and
                // InvoiceItem.total is tax-inclusive.
                return [
                    "tax_rate" => $item->tax_rate ?? 0,
                    "net_amount" => (float) $item->total - (float) $item->tax_amount,
                    "tax_amount" => (float) $item->tax_amount,
                    "gross_amount" => (float) $item->total,
                    "invoice_number" => $invoice->invoice_number,
                    "client_name" => $invoice->client->name ?? "N/A",
                ];
            });
        })->groupBy("tax_rate")
          ->map(function ($items, $rate) {
              return [
                  "tax_rate" => (float) $rate,
                  "transaction_count" => count($items),
                  "net_amount" => collect($items)->sum("net_amount"),
                  "tax_amount" => collect($items)->sum("tax_amount"),
                  "gross_amount" => collect($items)->sum("gross_amount"),
              ];
          })->sortByDesc("tax_rate");

        // Get bill line items with tax by rate (per-line GST treatment:
        // GST-free lines have tax_rate 0 and are naturally excluded by the
        // tax_rate > 0 filter below).
        $bills = Bill::whereBetween("bill_date", [$startDate, $endDate])
            ->whereNotIn("status", [Bill::STATUS_DRAFT, Bill::STATUS_CANCELLED])
            ->with(["supplier", "items"])
            ->get();

        // Bill items are entered GST-inclusive: total is the amount paid,
        // tax_amount the back-calculated GST portion.
        $purchasesByTaxRate = $bills->flatMap(function ($bill) {
            return $bill->items->map(function ($item) use ($bill) {
                return [
                    "tax_rate" => (float) ($item->tax_rate ?? 0),
                    "net_amount" => (float) $item->total - (float) $item->tax_amount,
                    "tax_amount" => (float) $item->tax_amount,
                    "gross_amount" => (float) $item->total,
                    "reference" => $bill->reference ?? $bill->bill_number,
                    "supplier_name" => $bill->supplier->name ?? "N/A",
                ];
            });
        })->filter(function ($item) {
            return $item["tax_rate"] > 0;
        })->groupBy("tax_rate")
          ->map(function ($items, $rate) {
              return [
                  "tax_rate" => (float) $rate,
                  "transaction_count" => count($items),
                  "net_amount" => collect($items)->sum("net_amount"),
                  "tax_amount" => collect($items)->sum("tax_amount"),
                  "gross_amount" => collect($items)->sum("gross_amount"),
              ];
          })->sortByDesc("tax_rate");

        // Summary totals
        $totalSalesTax = $salesByTaxRate->sum("tax_amount");
        $totalPurchaseTax = $purchasesByTaxRate->sum("tax_amount");
        $netTaxPayable = $totalSalesTax - $totalPurchaseTax;

        return view("reports.tax-summary", compact(
            "startDate", "endDate",
            "salesByTaxRate", "purchasesByTaxRate",
            "totalSalesTax", "totalPurchaseTax", "netTaxPayable"
        ));
    }

    /**
     * Export Account Statement to PDF
     */
    public function exportAccountStatementPdf(Request $request)
    {
        $startDate = $request->get("start_date")
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get("end_date")
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get("account_id");

        if (!$accountId) {
            return back()->with("error", "Please select an account");
        }

        $account = Account::find($accountId);
        $this->getReportingPeriod($endDate);
        $statement = $this->buildAccountStatement($account, $startDate, $endDate);

        $pdf = Pdf::loadView("reports.pdf.account-statement", [
            "account" => $account,
            "startDate" => $startDate,
            "endDate" => $endDate,
            "openingBalance" => $statement['opening_balance'],
            "closingBalance" => $statement['closing_balance'],
            "totalDebit" => $statement['total_debit'],
            "totalCredit" => $statement['total_credit'],
            "transactions" => $statement['transactions'],
        ]);

        $filename = "Account_Statement_{$account->code}_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}.pdf";
        return $pdf->download($filename);
    }

    /**
     * Export Tax Summary to PDF
     */
    public function exportTaxSummaryPdf(Request $request)
    {
        $startDate = $request->get("start_date")
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get("end_date")
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $invoices = \App\Models\Invoice::whereBetween("issue_date", [$startDate, $endDate])
            ->where("status", "!=", "cancelled")
            ->with("items")
            ->get();

        $salesByTaxRate = $invoices->flatMap(function ($invoice) {
            return $invoice->items->map(function ($item) use ($invoice) {
                // Stored line amounts: tax_amount is authoritative and
                // InvoiceItem.total is tax-inclusive.
                return [
                    "tax_rate" => $item->tax_rate ?? 0,
                    "net_amount" => (float) $item->total - (float) $item->tax_amount,
                    "tax_amount" => (float) $item->tax_amount,
                ];
            });
        })->groupBy("tax_rate")
          ->map(function ($items, $rate) {
              return [
                  "tax_rate" => (float) $rate,
                  "net_amount" => collect($items)->sum("net_amount"),
                  "tax_amount" => collect($items)->sum("tax_amount"),
              ];
          })->sortByDesc("tax_rate");

        $bills = Bill::whereBetween("bill_date", [$startDate, $endDate])
            ->whereNotIn("status", [Bill::STATUS_DRAFT, Bill::STATUS_CANCELLED])
            ->with("items")
            ->get();

        $purchasesByTaxRate = $bills->flatMap(function ($bill) {
            return $bill->items->map(function ($item) {
                return [
                    "tax_rate" => (float) ($item->tax_rate ?? 0),
                    "net_amount" => (float) $item->total - (float) $item->tax_amount,
                    "tax_amount" => (float) $item->tax_amount,
                ];
            });
        })->filter(fn($item) => $item["tax_rate"] > 0)
          ->groupBy("tax_rate")
          ->map(function ($items, $rate) {
              return [
                  "tax_rate" => (float) $rate,
                  "net_amount" => collect($items)->sum("net_amount"),
                  "tax_amount" => collect($items)->sum("tax_amount"),
              ];
          })->sortByDesc("tax_rate");

        $totalSalesTax = $salesByTaxRate->sum("tax_amount");
        $totalPurchaseTax = $purchasesByTaxRate->sum("tax_amount");
        $netTaxPayable = $totalSalesTax - $totalPurchaseTax;

        $pdf = Pdf::loadView("reports.pdf.tax-summary", [
            "startDate" => $startDate,
            "endDate" => $endDate,
            "salesByTaxRate" => $salesByTaxRate,
            "purchasesByTaxRate" => $purchasesByTaxRate,
            "totalSalesTax" => $totalSalesTax,
            "totalPurchaseTax" => $totalPurchaseTax,
            "netTaxPayable" => $netTaxPayable,
        ]);

        $filename = "Tax_Summary_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}.pdf";
        return $pdf->download($filename);
    }
    /**
     * Export Account Statement to Excel
     */
    public function exportAccountStatementExcel(Request $request)
    {
        $startDate = $request->get("start_date")
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get("end_date")
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get("account_id");

        if (!$accountId) {
            return back()->with("error", "Please select an account");
        }

        $account = Account::find($accountId);
        $this->getReportingPeriod($endDate);
        $statement = $this->buildAccountStatement($account, $startDate, $endDate);

        $export = new \App\Exports\AccountStatementExport(
            $account,
            $startDate,
            $endDate,
            $statement['opening_balance'],
            $statement['closing_balance'],
            $statement['total_debit'],
            $statement['total_credit'],
            $statement['transactions']
        );

        $filename = "Account_Statement_{$account->code}_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}.xlsx";
        return Excel::download($export, $filename);
    }

    /**
     * Export Tax Summary to Excel
     */
    public function exportTaxSummaryExcel(Request $request)
    {
        $startDate = $request->get("start_date")
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get("end_date")
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $invoices = \App\Models\Invoice::whereBetween("issue_date", [$startDate, $endDate])
            ->where("status", "!=", "cancelled")
            ->with("items")
            ->get();

        $salesByTaxRate = $invoices->flatMap(function ($invoice) {
            return $invoice->items->map(function ($item) {
                // Stored line amounts: tax_amount is authoritative and
                // InvoiceItem.total is tax-inclusive.
                return [
                    "tax_rate" => $item->tax_rate ?? 0,
                    "net_amount" => (float) $item->total - (float) $item->tax_amount,
                    "tax_amount" => (float) $item->tax_amount,
                ];
            });
        })->groupBy("tax_rate")
          ->map(function ($items, $rate) {
              return [
                  "tax_rate" => (float) $rate,
                  "net_amount" => collect($items)->sum("net_amount"),
                  "tax_amount" => collect($items)->sum("tax_amount"),
              ];
          })->sortByDesc("tax_rate");

        $bills = Bill::whereBetween("bill_date", [$startDate, $endDate])
            ->whereNotIn("status", [Bill::STATUS_DRAFT, Bill::STATUS_CANCELLED])
            ->with("items")
            ->get();

        $purchasesByTaxRate = $bills->flatMap(function ($bill) {
            return $bill->items->map(function ($item) {
                return [
                    "tax_rate" => (float) ($item->tax_rate ?? 0),
                    "net_amount" => (float) $item->total - (float) $item->tax_amount,
                    "tax_amount" => (float) $item->tax_amount,
                ];
            });
        })->filter(fn($item) => $item["tax_rate"] > 0)
          ->groupBy("tax_rate")
          ->map(function ($items, $rate) {
              return [
                  "tax_rate" => (float) $rate,
                  "net_amount" => collect($items)->sum("net_amount"),
                  "tax_amount" => collect($items)->sum("tax_amount"),
              ];
          })->sortByDesc("tax_rate");

        $totalSalesTax = $salesByTaxRate->sum("tax_amount");
        $totalPurchaseTax = $purchasesByTaxRate->sum("tax_amount");
        $netTaxPayable = $totalSalesTax - $totalPurchaseTax;

        $export = new \App\Exports\TaxSummaryExport(
            $startDate,
            $endDate,
            $salesByTaxRate,
            $purchasesByTaxRate,
            $totalSalesTax,
            $totalPurchaseTax,
            $netTaxPayable
        );

        $filename = "Tax_Summary_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}.xlsx";
        return Excel::download($export, $filename);
    }
}

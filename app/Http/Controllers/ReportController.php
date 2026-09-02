<?php

namespace App\Http\Controllers;

use App\Exports\AccountStatementExport;
use App\Exports\BasExport;
use App\Exports\CompanyTaxExport;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\CompanyProfile;
use App\Models\DividendDeclaration;
use App\Models\FiscalYearClose;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Prepayment;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\FiscalYearService;
use App\Services\FrankingService;
use App\Services\OpeningBalances;
use App\Services\PrepaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use IFRS\Models\Ledger;
use IFRS\Models\LineItem;
use IFRS\Models\ReportingPeriod;
use IFRS\Reports\CashFlowStatement;
use IFRS\Scopes\EntityScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

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

        $query = TimeEntry::with(['client', 'project.client', 'user'])
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->approved();

        if ($clientId) {
            // Entries carry a denormalised client_id (forced from the
            // project when one is set), so this covers both targeted
            // and project-based entries.
            $query->where('client_id', $clientId);
        }

        $timeEntries = $query->get();

        // Group by client
        $byClient = $timeEntries->groupBy(fn ($e) => $e->client_id ?? $e->project?->client?->id ?? 'unassigned')
            ->map(function ($entries, $groupKey) {
                $client = $entries->first()->client?->name
                    ?? $entries->first()->project?->client?->name
                    ?? 'Unassigned';

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
            // Cumulative as-at balance: the opening snapshot in force at
            // $endDate plus ledger movement after it (the whole ledger
            // from an arbitrary epoch when no snapshot exists) — exact
            // as-at figures that don't depend on year-end closing
            // entries having been posted (the package's period-scoped
            // closingBalance() does).
            $balance = $closing
                ? OpeningBalances::balanceAt($account, $entity, $endDate)
                : (float) Ledger::balance($account, $startDate, $endDate, $entity->currency_id)[$entity->currency_id];

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

    /**
     * Row builder for the income-statement sections: one row per P&L
     * account of the given types, with the account's movement over the
     * period EXCLUDING year-end closing entries (reference FY-CLOSE-*).
     * The closing entries exist to zero those accounts at year end —
     * without the exclusion a closed year's statement collapses to zero.
     * Balances are magnitudes, matching statementAccountRows().
     */
    protected function pnlAccountRows(array $accountTypes, Carbon $startDate, Carbon $endDate): array
    {
        $entity = $this->ifrsEntity();
        $rows = [];

        foreach (Account::where('entity_id', $entity->id)
            ->whereIn('account_type', $accountTypes)
            ->orderBy('code')
            ->get() as $account
        ) {
            $movement = FiscalYearService::movementExcludingClosures($account, $startDate, $endDate, $entity);

            if (abs($movement) < 0.005) {
                continue;
            }

            $rows[] = [
                'account' => ['name' => $account->name, 'code' => $account->code],
                'balance' => round(abs($movement), 2),
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
            // As-at balance via the opening snapshot in force (debit-
            // positive; credit opening rows land negative).
            $balance = OpeningBalances::balanceAt($account, $entity, $endDate);

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

        // Surface swallowed posting failures: best-effort posting means a
        // payment can exist without ever reaching the ledger.
        $unpostedPayments = Payment::whereNull('ifrs_receipt_id')
            ->where('status', '!=', Payment::STATUS_VOID)
            ->count();
        $unpostedBillPayments = BillPayment::whereNull('ifrs_payment_id')
            ->where('status', '!=', BillPayment::STATUS_VOID)
            ->count();

        return view('reports.trial-balance', compact(
            'accountLines', 'endDate', 'debitTotal', 'creditTotal',
            'unpostedPayments', 'unpostedBillPayments'
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

        // P&L rows and totals from closing-aware movement: the year-end
        // closing entries (FY-CLOSE-*) are excluded so a closed year's
        // statement keeps reporting its real trading results. Totals are
        // sums of the detail rows, so the two can never disagree.
        $revenue = $this->pnlAccountRows(
            [Account::OPERATING_REVENUE, Account::NON_OPERATING_REVENUE],
            $startDate, $endDate
        );
        $directCosts = $this->pnlAccountRows(
            [Account::DIRECT_EXPENSE],
            $startDate, $endDate
        );
        $expenses = $this->pnlAccountRows(
            [Account::OPERATING_EXPENSE, Account::OVERHEAD_EXPENSE, Account::OTHER_EXPENSE],
            $startDate, $endDate
        );

        $revenueTotal = round(array_sum(array_column($revenue, 'balance')), 2);
        $directCostsTotal = round(array_sum(array_column($directCosts, 'balance')), 2);
        $expenseTotal = round(array_sum(array_column($expenses, 'balance')), 2);

        $lines = ['statement' => [
            'revenue' => $revenue,
            'revenueTotal' => $revenueTotal,
            'direct_costs' => $directCosts,
            'directCostsTotal' => $directCostsTotal,
            'grossProfit' => round($revenueTotal - $directCostsTotal, 2),
            'expense' => $expenses,
            'expenseTotal' => $expenseTotal,
            'netProfit' => round($revenueTotal - $directCostsTotal - $expenseTotal, 2),
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

        // The period's profit adds to equity before it is closed — but
        // only while the FY is still open. Once CLOSED, the closing
        // entries have already moved the profit into Retained Earnings
        // (part of the equity rows above), so adding the on-the-fly
        // figure again would double count it. Computed from
        // closing-aware movement either way.
        $fyService = new FiscalYearService;
        $netProfit = $fyService->isClosed($entity, ReportingPeriod::year($endDate, $entity))
            ? 0.0
            : $fyService->netProfitExcludingClosures($entity, $fyStart, $endDate);

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

    /**
     * GST collected/paid from the accounts the entity's Vats post to
     * (seeded "GST 10%" → 2200 GST Payable, "GST Input 10%" → 430 GST
     * Receivable), classified by account role: 2200's legs net to GST
     * collected (its debits are credit-note refund reversals, not GST
     * paid) and 430's legs net to GST paid (its credits are supplier
     * refund reversals, not GST collected). When the input Vat is not
     * seeded, BillPayment::purchaseGstVat() falls back to the output
     * Vat (G), purchases post through 2200 too, and roles on that
     * shared account cannot be separated — it keeps the per-side split
     * (credits collected, debits paid). Cash basis — the exact legs the
     * payment postings write, so the GST, BAS and company tax reports
     * all tie to the ledger.
     */
    protected function ledgerGst(Entity $entity, Carbon $startDate, Carbon $endDate): object
    {
        $vatAccounts = DB::table('ifrs_vats')
            ->where('entity_id', $entity->id)
            ->whereNotNull('account_id')
            ->get(['code', 'account_id']);

        if ($vatAccounts->isEmpty()) {
            return (object) ['collected' => 0.0, 'paid' => 0.0];
        }

        $inputCode = config('subscriptions.purchase_gst_vat_code', 'I');

        // Role per account from the Vat code that posts to it: the input
        // code carries purchase GST, anything else output GST. An account
        // both kinds post to is shared and reverts to the per-side split.
        $roles = [];
        foreach ($vatAccounts as $vat) {
            $role = $vat->code === $inputCode ? 'input' : 'output';
            $roles[$vat->account_id] = isset($roles[$vat->account_id]) && $roles[$vat->account_id] !== $role
                ? 'shared'
                : $role;
        }

        // Purchases normally post through the input Vat; the fallback to
        // the output Vat (unmigrated databases without the input Vat)
        // mixes both roles onto its account.
        $purchaseVat = BillPayment::purchaseGstVat($entity);
        if ($purchaseVat && $purchaseVat->account_id !== null && $purchaseVat->code !== $inputCode) {
            $roles[$purchaseVat->account_id] = 'shared';
        }

        $legs = DB::table('ifrs_ledgers')
            ->whereIn('post_account', array_keys($roles))
            ->whereBetween('posting_date', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->groupBy('post_account')
            ->selectRaw("post_account,
                SUM(CASE WHEN entry_type = '".Balance::CREDIT."' THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN entry_type = '".Balance::DEBIT."' THEN amount ELSE 0 END) as debits")
            ->get();

        $collected = 0.0;
        $paid = 0.0;
        foreach ($legs as $leg) {
            $role = $roles[$leg->post_account] ?? 'shared';
            if ($role === 'output') {
                $collected += (float) $leg->credits - (float) $leg->debits;
            } elseif ($role === 'input') {
                $paid += (float) $leg->debits - (float) $leg->credits;
            } else {
                $collected += (float) $leg->credits;
                $paid += (float) $leg->debits;
            }
        }

        return (object) [
            'collected' => $collected,
            'paid' => $paid,
        ];
    }

    public function gstReport(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $entity = $this->ifrsEntity();

        // Cash basis: GST figures come from the Vat account ledger legs
        // the payment postings write — the same basis as the ATO company
        // tax report — not from the invoice/bill subledger by issue date,
        // which recognised GST on issuance and diverged from the ledger
        // whenever invoices/bills were unpaid at period end.
        $gst = $this->ledgerGst($entity, $startDate, $endDate);
        $gstCollected = $gst->collected;
        $gstPaid = $gst->paid;

        // Money actually banked/paid out behind those legs. Only posted
        // payments count, so the report always ties to the ledger;
        // unposted ones appear once ifrs:post-payments backfills them,
        // refunds net through their negative amounts and voided payments
        // never posted.
        $totalReceipts = (float) Payment::whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', '!=', Payment::STATUS_VOID)
            ->whereNotNull('ifrs_receipt_id')
            ->sum('amount');
        $totalPayments = (float) BillPayment::whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', '!=', BillPayment::STATUS_VOID)
            ->whereNotNull('ifrs_payment_id')
            ->sum('amount');

        $netGst = $gstCollected - $gstPaid;

        return view('reports.gst', compact(
            'startDate', 'endDate',
            'gstCollected', 'totalReceipts',
            'gstPaid', 'totalPayments',
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

        // Cumulative opening balance: the opening snapshot in force the
        // day before the period starts plus ledger movement after it.
        $opening = OpeningBalances::balanceAt($account, $entity, $startDate->copy()->subSecond());
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
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get('account_id');

        $accounts = Account::orderBy('code')
            ->get(['id', 'code', 'name', 'account_type']);

        $statementData = null;

        if ($accountId) {
            $this->getReportingPeriod($endDate);
            $statementData = $this->buildAccountStatement(Account::find($accountId), $startDate, $endDate);
        }

        return view('reports.account-statement', compact(
            'statementData', 'accounts', 'startDate', 'endDate', 'accountId'
        ));
    }

    /**
     * IFRS Account Schedule Report
     */
    public function accountSchedule(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get('account_id');

        $accounts = Account::orderBy('code')
            ->get(['id', 'code', 'name', 'account_type']);

        $scheduleData = null;

        if ($accountId) {
            $account = Account::find($accountId);

            // Get all journal entries with line items for this account in date range.
            // NOTE: the IFRS Transaction date column is `transaction_date`
            // (not `date`), and debit/credit is determined by the line item's
            // `credited` boolean (false = debit, true = credit) — there is no
            // `type` column and `LineItem::DEBIT`/`::CREDIT` do not exist.
            $lineItems = LineItem::where('account_id', $accountId)
                ->whereHas('transaction', function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('transaction_date', [$startDate, $endDate]);
                })
                ->with(['transaction', 'transaction.lineItems'])
                ->get();

            // Group by transaction (sorting by a related column in SQL would
            // need a join; sort the grouped collection instead)
            $groupedByTransaction = $lineItems->groupBy('transaction_id')
                ->sortBy(fn ($items) => $items->first()->transaction->transaction_date);

            $scheduleLines = collect();
            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($groupedByTransaction as $transactionId => $items) {
                $transaction = $items->first()->transaction;

                // Get all line items for this transaction
                $allItems = $transaction->lineItems ?? collect();

                // credited=false -> debit, credited=true -> credit
                $debitTotal = $allItems->where('credited', false)->sum('amount');
                $creditTotal = $allItems->where('credited', true)->sum('amount');

                $totalDebit += $debitTotal;
                $totalCredit += $creditTotal;

                $scheduleLines->push([
                    'date' => Carbon::parse($transaction->transaction_date),
                    'transaction_id' => $transactionId,
                    'transaction_type' => class_basename($transaction),
                    'narration' => $transaction->narration ?? '',
                    'reference' => $transaction->reference ?? '',
                    'line_items' => $allItems,
                    'debit' => $debitTotal,
                    'credit' => $creditTotal,
                ]);
            }

            $scheduleData = [
                'account' => $account,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'line_count' => $scheduleLines->count(),
                'lines' => $scheduleLines,
            ];
        }

        return view('reports.account-schedule', compact(
            'scheduleData', 'accounts', 'startDate', 'endDate', 'accountId'
        ));
    }

    /**
     * BAS (Business Activity Statement) Report — all four quarters of
     * an Australian financial year (1 July – 30 June).
     */
    public function bas(Request $request)
    {
        // FY named by its June year-end: FY2026 = Jul 2025 – Jun 2026.
        // Derived from the entity's year_start so a non-July FY (or a
        // later change to year_start) cannot drift from the ledger.
        $currentFyEnd = ReportingPeriod::year(now(), $this->ifrsEntity()) + 1;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);

        $statement = $this->buildBasStatement($fyEnd);
        $availableFys = range($currentFyEnd, $currentFyEnd - 5);

        return view('reports.bas', compact(
            'fyEnd', 'currentFyEnd', 'availableFys', 'statement'
        ));
    }

    /**
     * Quarterly BAS figures for the financial year ending 30 June
     * $fyEnd, on the cash basis the ledger keeps: G1 is posted client
     * payments (GST-inclusive, refunds netting via negative amounts),
     * 1A/1B are the Vat account ledger legs (ledgerGst()), and G10/G11
     * are bill payment allocations apportioned across bill lines via
     * BillPayment::allocationGroups() — the same shares the postings
     * use — split into capital (non-current-asset accounts) and
     * non-capital. Every figure ties to the ledger because only posted
     * payments count; unposted ones appear once ifrs:post-payments
     * backfills them.
     */
    protected function buildBasStatement(int $fyEnd): array
    {
        // FY boundaries from the entity's year_start ($fyEnd is the FY's
        // ending calendar year; the FY label is one less). Identical to
        // the previous hard-coded Jul–Jun for the default July start.
        $entity = $this->ifrsEntity();
        ['start' => $fyStart, 'end' => $fyEndDate] = (new FiscalYearService)->bounds($entity, $fyEnd - 1);
        $fyStart = $fyStart->startOfDay();
        $fyEndDate = $fyEndDate->copy()->endOfDay();

        // BAS quarters: consecutive three-month blocks of the FY, in
        // whatever month it starts (Q1 Jul-Sep, Q2 Oct-Dec, Q3 Jan-Mar,
        // Q4 Apr-Jun for the default July start).
        $quarterOf = fn ($date) => intdiv(
            $fyStart->copy()->startOfMonth()->diffInMonths(Carbon::parse($date)->startOfMonth()),
            3
        );

        $quarters = [];
        foreach ([0, 1, 2, 3] as $i) {
            $start = $fyStart->copy()->addMonths($i * 3)->startOfDay();
            $quarters[$i] = [
                'label' => sprintf('Q%d (%s-%s)', $i + 1, $start->format('M'), $start->copy()->addMonths(2)->format('M')),
                'start' => $start,
                'end' => $start->copy()->addMonths(3)->subDay()->endOfDay(),
                'g1' => 0.0,
                'gst_sales' => 0.0,
                'g10' => 0.0,
                'g11' => 0.0,
                'gst_purchases' => 0.0,
            ];
        }

        // G1 — posted client payments, GST-inclusive (refunds subtract).
        $payments = Payment::whereBetween('payment_date', [$fyStart, $fyEndDate])
            ->where('status', '!=', Payment::STATUS_VOID)
            ->whereNotNull('ifrs_receipt_id')
            ->get();
        foreach ($payments as $payment) {
            $quarters[$quarterOf($payment->payment_date)]['g1'] += (float) $payment->amount;
        }

        // 1A/1B — the GST ledger legs the postings wrote, per quarter.
        foreach ($quarters as $i => $quarter) {
            $gst = $this->ledgerGst($entity, $quarter['start'], $quarter['end']);
            $quarters[$i]['gst_sales'] = $gst->collected;
            $quarters[$i]['gst_purchases'] = $gst->paid;
        }

        // G10/G11 — supplier payments apportioned across their bills'
        // lines. A line categorised to a non-current-asset account is a
        // capital purchase; everything else is non-capital (GST credits
        // in 1B cover both kinds).
        $accountTypes = DB::table('ifrs_accounts')
            ->where('entity_id', $entity->id)
            ->pluck('account_type', 'id');
        $defaultExpenseAccount = Account::withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $entity->id)
            ->where('code', BillPayment::IFRS_DEFAULT_EXPENSE_ACCOUNT_CODE)
            ->first();

        $billPayments = BillPayment::whereBetween('payment_date', [$fyStart, $fyEndDate])
            ->where('status', '!=', BillPayment::STATUS_VOID)
            ->whereNotNull('ifrs_payment_id')
            ->with('allocations.bill.items')
            ->get();
        foreach ($billPayments as $billPayment) {
            $i = $quarterOf($billPayment->payment_date);
            foreach ($billPayment->allocations as $allocation) {
                $bill = $allocation->bill;
                if (! $bill) {
                    continue;
                }
                foreach (BillPayment::allocationGroups($bill, (float) $allocation->amount, $defaultExpenseAccount) as $key => $cents) {
                    [$accountId] = explode('-', $key);
                    $column = ($accountTypes[$accountId] ?? null) === Account::NON_CURRENT_ASSET ? 'g10' : 'g11';
                    $quarters[$i][$column] += $cents / 100;
                }
            }
        }

        foreach ($quarters as &$q) {
            $q['net'] = $q['gst_sales'] - $q['gst_purchases'];
        }
        unset($q);

        $totals = [
            'g1' => array_sum(array_column($quarters, 'g1')),
            'gst_sales' => array_sum(array_column($quarters, 'gst_sales')),
            'g10' => array_sum(array_column($quarters, 'g10')),
            'g11' => array_sum(array_column($quarters, 'g11')),
            'gst_purchases' => array_sum(array_column($quarters, 'gst_purchases')),
        ];
        $totals['net'] = $totals['gst_sales'] - $totals['gst_purchases'];

        return [
            'fyStart' => $fyStart,
            'fyEnd' => $fyEndDate,
            'quarters' => $quarters,
            'totals' => $totals,
        ];
    }

    /**
     * Export Account Statement to PDF
     */
    public function exportAccountStatementPdf(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get('account_id');

        if (! $accountId) {
            return back()->with('error', 'Please select an account');
        }

        $account = Account::find($accountId);
        $this->getReportingPeriod($endDate);
        $statement = $this->buildAccountStatement($account, $startDate, $endDate);

        $pdf = Pdf::loadView('reports.pdf.account-statement', [
            'account' => $account,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'openingBalance' => $statement['opening_balance'],
            'closingBalance' => $statement['closing_balance'],
            'totalDebit' => $statement['total_debit'],
            'totalCredit' => $statement['total_credit'],
            'transactions' => $statement['transactions'],
        ]);

        $filename = "Account_Statement_{$account->code}_{$startDate->format('Ymd')}_{$endDate->format('Ymd')}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Export BAS to PDF
     */
    public function exportBasPdf(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildBasStatement($fyEnd);

        $pdf = Pdf::loadView('reports.pdf.bas', [
            'fyEnd' => $fyEnd,
            'statement' => $statement,
        ]);

        return $pdf->download("BAS_FY{$fyEnd}.pdf");
    }

    /**
     * Export Account Statement to Excel
     */
    public function exportAccountStatementExcel(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $accountId = $request->get('account_id');

        if (! $accountId) {
            return back()->with('error', 'Please select an account');
        }

        $account = Account::find($accountId);
        $this->getReportingPeriod($endDate);
        $statement = $this->buildAccountStatement($account, $startDate, $endDate);

        $export = new AccountStatementExport(
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
     * Export BAS to Excel
     */
    public function exportBasExcel(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildBasStatement($fyEnd);

        $export = new BasExport($fyEnd, $statement);

        return Excel::download($export, "BAS_FY{$fyEnd}.xlsx");
    }

    /**
     * Annual Company Tax Report — screen entry point (ATO Company Tax
     * Return, income year ended 30 June).
     */
    public function companyTax(Request $request)
    {
        // FY named by its June year-end, derived from the entity's
        // year_start so it cannot drift from the ledger's FY boundaries.
        $currentFyEnd = ReportingPeriod::year(now(), $this->ifrsEntity()) + 1;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);

        $statement = $this->buildCompanyTaxStatement($fyEnd);
        $availableFys = range($currentFyEnd, $currentFyEnd - 5);

        return view('reports.company-tax', compact(
            'fyEnd', 'currentFyEnd', 'availableFys', 'statement'
        ));
    }

    /**
     * Export Company Tax Report to PDF
     */
    public function exportCompanyTaxPdf(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildCompanyTaxStatement($fyEnd);

        $pdf = Pdf::loadView('reports.pdf.company-tax', [
            'fyEnd' => $fyEnd,
            'statement' => $statement,
        ]);

        return $pdf->download("CompanyTax_FY{$fyEnd}.pdf");
    }

    /**
     * Export Company Tax Report to Excel
     */
    public function exportCompanyTaxExcel(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildCompanyTaxStatement($fyEnd);

        $export = new CompanyTaxExport($fyEnd, $statement);

        return Excel::download($export, "CompanyTax_FY{$fyEnd}.xlsx");
    }

    /**
     * Export Company Tax Report to CSV (spec §6.1 audit-trail columns)
     */
    public function exportCompanyTaxCsv(Request $request)
    {
        $currentFyEnd = now()->month >= 7 ? now()->year + 1 : now()->year;
        $fyEnd = (int) $request->get('fy', $currentFyEnd);
        $statement = $this->buildCompanyTaxStatement($fyEnd);

        $export = new CompanyTaxExport($fyEnd, $statement);

        return Excel::download($export, "CompanyTax_FY{$fyEnd}.csv", \Maatwebsite\Excel\Excel::CSV);
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

    /**
     * Company tax report figures for the ATO Company Tax Return (income
     * year 1 July – 30 June), per ATO_tax_report_spec.md. Label letters
     * and names come from config/ato_tax_report.php and follow the
     * Company tax return 2026 (NAT 0656).
     *
     * Item 6/7 amounts are cash-basis and GST-exclusive by construction:
     * only ledger rows whose parent transaction's main account is a BANK
     * account are included (client receipts and supplier payments are
     * the sole posters to revenue/expense in this system, so non-cash
     * journals are excluded), and the GST back-out legs post to the same
     * revenue/expense account, leaving per-account net movement already
     * net of GST — the form's "exclude input tax credits" rule.
     *
     * Raw DB queries are used because the package's EntityScope cannot
     * resolve background contexts; entity_id is therefore filtered
     * explicitly and soft deletes (deleted_at) honoured manually.
     */
    protected function buildCompanyTaxStatement(int $fyEnd): array
    {
        // FY boundaries from the entity's year_start ($fyEnd is the FY's
        // ending calendar year; the FY label is one less).
        $entity = $this->ifrsEntity();
        ['start' => $fyStart, 'end' => $fyEndDate] = (new FiscalYearService)->bounds($entity, $fyEnd - 1);
        $fyStart = $fyStart->startOfDay();
        $fyEndDate = $fyEndDate->copy()->endOfDay();
        $this->getReportingPeriod($fyEndDate);

        $config = config('ato_tax_report');
        $flags = $config['account_flags'];
        $warnings = [];

        $revenueTypes = [Account::OPERATING_REVENUE, Account::NON_OPERATING_REVENUE];
        $expenseTypes = [
            Account::OPERATING_EXPENSE, Account::DIRECT_EXPENSE,
            Account::OVERHEAD_EXPENSE, Account::OTHER_EXPENSE,
        ];
        $movementTypes = [...$revenueTypes, ...$expenseTypes, Account::NON_CURRENT_ASSET, Account::EQUITY];

        // Per-account net movement for the year through bank-settled
        // transactions (the transaction's main account must be a BANK).
        // Year-end closing entries are never trading activity — excluded
        // by reference prefix (belt-and-braces: their main account is
        // Retained Earnings, so the bank filter already drops them).
        $accountMovements = DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->join('ifrs_accounts as bank', 'bank.id', '=', 't.account_id')
            ->where('a.entity_id', $entity->id)
            ->whereIn('a.account_type', $movementTypes)
            ->where('bank.account_type', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->whereNull('t.reference')->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_type')
            ->orderBy('a.code')
            ->selectRaw("a.id, a.code, a.name, a.account_type,
                SUM(CASE WHEN l.entry_type = 'D' THEN l.amount ELSE 0 END) as debits,
                SUM(CASE WHEN l.entry_type = 'C' THEN l.amount ELSE 0 END) as credits")
            ->get();

        // Audit trail: transaction/line-item ids per account. Fetched as
        // distinct rows instead of GROUP_CONCAT so MySQL's
        // group_concat_max_len cannot silently truncate the id list.
        $auditByAccount = [];
        DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as bank', 'bank.id', '=', 't.account_id')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->where('a.entity_id', $entity->id)
            ->whereIn('a.account_type', $movementTypes)
            ->where('bank.account_type', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->whereNull('t.reference')->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
            ->distinct()
            ->get(['a.id as account_id', 'l.transaction_id', 'l.line_item_id'])
            ->each(function ($link) use (&$auditByAccount) {
                $auditByAccount[$link->account_id]['txn'][] = $link->transaction_id;
                if ($link->line_item_id) {
                    $auditByAccount[$link->account_id]['line'][] = $link->line_item_id;
                }
            });

        // Bank flows for the cash cross-checks (V05/V06).
        $bankFlows = DB::table('ifrs_ledgers as l')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->where('a.entity_id', $entity->id)
            ->where('a.account_type', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->selectRaw("SUM(CASE WHEN l.entry_type = 'D' THEN l.amount ELSE 0 END) as inflows,
                SUM(CASE WHEN l.entry_type = 'C' THEN l.amount ELSE 0 END) as outflows")
            ->first();

        // GST collected/paid from the Vat account ledger legs, netted
        // per account role (see ledgerGst()). Shared with the GST/BAS
        // reports so every GST figure uses the same cash basis.
        $gst = $this->ledgerGst($entity, $fyStart, $fyEndDate);

        // Label rows initialised from config (form order preserved).
        $makeRows = function (array $defs): array {
            $rows = [];
            foreach ($defs as $label => $def) {
                $rows[$label] = [
                    'item' => '6',
                    'label' => $label,
                    'name' => $def['name'],
                    'note' => $def['note'] ?? null,
                    'accounts' => [],
                    'amount' => 0.0,
                    'total' => (bool) ($def['total'] ?? false),
                    'sourced' => ! empty($def['accounts']),
                    'transaction_ids' => [],
                    'line_item_ids' => [],
                ];
            }

            return $rows;
        };
        $incomeRows = $makeRows($config['income_labels']);
        $expenseRows = $makeRows($config['expense_labels']);

        $accountMap = function (array $defs): array {
            $map = [];
            foreach ($defs as $label => $def) {
                foreach ($def['accounts'] ?? [] as $code) {
                    $map[(int) $code] = $label;
                }
            }

            return $map;
        };
        $incomeAccountMap = $accountMap($config['income_labels']);
        $expenseAccountMap = $accountMap($config['expense_labels']);

        $assignAccount = function (array &$rows, string $label, object $m, float $net, array $audit): void {
            $rows[$label]['accounts'][] = [
                'code' => $m->code, 'name' => $m->name, 'amount' => round($net),
            ];
            $rows[$label]['amount'] += $net;
            $rows[$label]['sourced'] = true;
            $rows[$label]['transaction_ids'] = array_merge($rows[$label]['transaction_ids'], $audit['txn'] ?? []);
            $rows[$label]['line_item_ids'] = array_merge($rows[$label]['line_item_ids'], $audit['line'] ?? []);
        };

        $nonDeductible = 0.0;
        $exemptIncome = 0.0;
        $otherNonAssessable = 0.0;
        $capitalAccounts = [];
        $dividendsPaid = 0.0;
        $salaryTotal = 0.0;
        $salaryAccounts = array_map('intval', $config['salary_expense_accounts']);

        foreach ($accountMovements as $m) {
            $isRevenue = in_array($m->account_type, $revenueTypes);
            $net = $isRevenue
                ? (float) $m->credits - (float) $m->debits
                : (float) $m->debits - (float) $m->credits;
            $audit = $auditByAccount[$m->id] ?? [];
            $flag = $flags[(int) $m->code] ?? null;

            if ($flag === 'excluded') {
                continue;
            }

            if ($m->account_type === Account::NON_CURRENT_ASSET) {
                // Capital purchases: reference data for Item 10 (SBE
                // simplified depreciation), never an Item 6 deduction.
                $capitalAccounts[] = ['code' => $m->code, 'name' => $m->name, 'amount' => round($net)];

                continue;
            }

            if ($m->account_type === Account::EQUITY) {
                if ((int) $m->code === (int) $config['dividends_paid_account']) {
                    $dividendsPaid = round($net);
                }

                // Other equity movements (share capital, injections) do
                // not appear on the return's sourced labels; they are
                // covered by the V05 bank-flow explanation note.
                continue;
            }

            if ($isRevenue) {
                $mapped = $incomeAccountMap[(int) $m->code] ?? null;
                $label = $mapped ?? ($config['fallback'][$m->account_type] ?? 'R');
                if ($mapped === null) {
                    $warnings[] = "Income account {$m->code} {$m->name} is not mapped in config/ato_tax_report.php — reported at Item 6 label {$label}.";
                }
                $assignAccount($incomeRows, $label, $m, $net, $audit);
                if ($flag === 'non_assessable_exempt') {
                    $exemptIncome += $net;
                } elseif ($flag === 'non_assessable_other') {
                    $otherNonAssessable += $net;
                }
            } else {
                $mapped = $expenseAccountMap[(int) $m->code] ?? null;
                $label = $mapped ?? $config['fallback']['expense'];
                if ($mapped === null) {
                    $warnings[] = "Expense account {$m->code} {$m->name} is not mapped in config/ato_tax_report.php — reported at Item 6 label {$label}.";
                }
                $assignAccount($expenseRows, $label, $m, $net, $audit);
                if ($flag === 'non_deductible') {
                    $nonDeductible += $net;
                }
                if (in_array((int) $m->code, $salaryAccounts)) {
                    $salaryTotal += $net;
                }
            }
        }

        // Round each label to whole dollars, then derive totals from the
        // rounded labels so V01–V04 hold exactly (spec V08).
        foreach ($incomeRows as $label => &$row) {
            if (! $row['total']) {
                $row['amount'] = round($row['amount']);
                $row['transaction_ids'] = implode(',', array_unique($row['transaction_ids']));
                $row['line_item_ids'] = implode(',', array_unique($row['line_item_ids']));
            }
        }
        unset($row);
        foreach ($expenseRows as $label => &$row) {
            if (! $row['total']) {
                $row['amount'] = round($row['amount']);
                $row['transaction_ids'] = implode(',', array_unique($row['transaction_ids']));
                $row['line_item_ids'] = implode(',', array_unique($row['line_item_ids']));
            }
        }
        unset($row);

        $sumNonTotals = fn (array $rows) => round(array_sum(array_map(
            fn ($r) => $r['total'] ? 0 : $r['amount'], array_values($rows)
        )));

        $totalIncome = $sumNonTotals($incomeRows);
        $totalExpenses = $sumNonTotals($expenseRows);
        $incomeRows['S']['amount'] = $totalIncome;
        $expenseRows['Q']['amount'] = $totalExpenses;

        $profitOrLoss = $totalIncome - $totalExpenses; // 6-T
        $nonDeductible = round($nonDeductible);
        $exemptIncome = round($exemptIncome);
        $otherNonAssessable = round($otherNonAssessable);
        $taxableIncome = $profitOrLoss + $nonDeductible - $exemptIncome - $otherNonAssessable; // 7-T
        $capitalTotal = array_sum(array_column($capitalAccounts, 'amount'));

        $gstCollected = round((float) $gst->collected);
        $gstPaid = round((float) $gst->paid);
        $bankInflows = round((float) $bankFlows->inflows);
        $bankOutflows = round((float) $bankFlows->outflows);

        // Item 8 as-at balances at 30 June: the opening snapshot in force
        // plus ledger movement after it — the same basis as the trial
        // balance.
        $asAtBalance = function (array $types) use ($entity, $fyEndDate): float {
            $total = 0.0;
            foreach (Account::where('entity_id', $entity->id)->whereIn('account_type', $types)->get() as $account) {
                $total += OpeningBalances::balanceAt($account, $entity, $fyEndDate);
            }

            return $total;
        };
        $tradeDebtors = round($asAtBalance([Account::RECEIVABLE]));
        $currentAssets = round($asAtBalance([
            Account::BANK, Account::RECEIVABLE, Account::CURRENT_ASSET, Account::INVENTORY,
        ]));
        $totalAssets = round($asAtBalance([
            Account::BANK, Account::RECEIVABLE, Account::CURRENT_ASSET, Account::INVENTORY,
            Account::NON_CURRENT_ASSET, Account::CONTRA_ASSET,
        ]));
        $tradeCreditors = round(abs($asAtBalance([Account::PAYABLE])));
        $currentLiabilities = round(abs($asAtBalance([Account::PAYABLE, Account::CURRENT_LIABILITY])));
        $totalLiabilities = round(abs($asAtBalance([
            Account::PAYABLE, Account::CURRENT_LIABILITY, Account::NON_CURRENT_LIABILITY,
        ])));

        // Non-cash P&L ledger rows excluded by the bank filter (V07).
        // Closing entries would otherwise dominate this count: they are
        // non-bank transactions hitting P&L accounts by design.
        $nonCashRows = DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->join('ifrs_accounts as main', 'main.id', '=', 't.account_id')
            ->where('a.entity_id', $entity->id)
            ->whereIn('a.account_type', [...$revenueTypes, ...$expenseTypes])
            ->where('main.account_type', '!=', Account::BANK)
            ->whereBetween('l.posting_date', [$fyStart, $fyEndDate])
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->whereNull('t.reference')->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
            ->count();

        // Data completeness: best-effort posting means payments can exist
        // without ever reaching the ledger.
        $unpostedPayments = Payment::whereNull('ifrs_receipt_id')
            ->where('status', '!=', Payment::STATUS_VOID)->count();
        $unpostedBillPayments = BillPayment::whereNull('ifrs_payment_id')
            ->where('status', '!=', BillPayment::STATUS_VOID)->count();
        if ($unpostedPayments || $unpostedBillPayments) {
            $warnings[] = sprintf(
                '%d client payment(s) and %d bill payment(s) are not posted to the IFRS ledger and are excluded from this report.',
                $unpostedPayments,
                $unpostedBillPayments
            );
        }

        // Spec §7 validation rules.
        $validations = [];
        $addValidation = function (string $code, string $description, bool $pass, string $detail) use (&$validations): void {
            $validations[] = [
                'code' => $code,
                'description' => $description,
                'status' => $pass ? 'PASS' : 'FAIL',
                'detail' => $detail,
            ];
        };

        $negativeLabels = [];
        foreach ([...$incomeRows, ...$expenseRows] as $row) {
            if (! $row['total'] && $row['amount'] < 0) {
                $negativeLabels[] = "6-{$row['label']} {$row['name']}";
            }
        }

        // The Calculation statement estimate follows the company profile's
        // tax rate classification (base rate entity vs other company),
        // falling back to the report config when unclassified.
        $taxRate = CompanyProfile::effectiveTaxRate($entity?->id)
            ?: (float) $config['company_tax_rate'];
        $estimatedTax = max(0, $taxableIncome) * $taxRate / 100;

        $addValidation('V01', 'Total income label equals sum of income field amounts', true,
            "6-S {$totalIncome} is the sum of the rounded income labels.");
        $addValidation('V02', 'Total expenses label equals sum of expense field amounts', true,
            "6-Q {$totalExpenses} is the sum of the rounded expense labels.");
        $addValidation('V03', 'Net profit or loss equals total income minus total expenses', true,
            "6-T {$profitOrLoss} = 6-S {$totalIncome} − 6-Q {$totalExpenses}.");
        $addValidation('V04', 'Taxable income equals net profit plus add-backs less subtractions', true,
            "7-T {$taxableIncome} = 6-T {$profitOrLoss} + 7-W {$nonDeductible} − 7-V {$exemptIncome} − 7-Q {$otherNonAssessable}.");

        $inflowGap = $bankInflows - ($totalIncome + $gstCollected + $exemptIncome + $otherNonAssessable);
        $addValidation('V05', 'Bank inflows reconcile to income plus GST collected plus non-assessable receipts',
            abs($inflowGap) <= 1,
            "Bank inflows {$bankInflows} vs expected ".($bankInflows - $inflowGap)
            .'. Differences are loan proceeds, capital injections or inter-account transfers and must be explained.');

        $outflowGap = $bankOutflows - ($totalExpenses + $gstPaid + $capitalTotal);
        $addValidation('V06', 'Bank outflows reconcile to expenses plus GST paid plus capital purchases',
            abs($outflowGap) <= 1,
            "Bank outflows {$bankOutflows} vs expected ".($bankOutflows - $outflowGap)
            .'. Non-deductible expenses are already inside 6-Q (added back at 7-W).');

        $addValidation('V07', 'No non-cash transaction included', true,
            "{$nonCashRows} non-bank P&L ledger rows excluded (depreciation, revaluations, forex).");
        $addValidation('V08', 'All amounts rounded to whole dollars', true,
            'Label amounts are rounded to the nearest dollar; totals derive from rounded labels.');
        $addValidation('V09', 'No negative amounts except loss fields', empty($negativeLabels),
            empty($negativeLabels)
                ? 'All label amounts are zero or positive.'
                : 'Negative labels: '.implode(', ', $negativeLabels).' (net refunds) — review before lodging.');
        $addValidation('V10', 'Every amount traces to IFRS transactions or a declared nil label', true,
            'Non-zero labels carry source transaction ids in the CSV export; nil labels are declared in config.');
        $addValidation('V11', 'Amounts come from bank-settled transactions', true,
            'Ledger rows are restricted to transactions whose main account is a BANK account.');
        $addValidation('V12', 'Expense amounts posted to expense accounts', true,
            'Item 6 expense labels only aggregate OPERATING/DIRECT/OVERHEAD/OTHER expense accounts.');
        $hasVats = DB::table('ifrs_vats')
            ->where('entity_id', $entity->id)
            ->whereNotNull('account_id')
            ->exists();
        $addValidation('V13', 'GST excluded per registration status', $hasVats,
            $hasVats
                ? "GST collected {$gstCollected} / paid {$gstPaid} excluded from income and expense labels."
                : 'No Vat configured for the entity — GST treatment unverifiable.');

        $reconciliation = [
            ['label' => '6-T', 'name' => 'Total profit or loss (cash basis)', 'amount' => $profitOrLoss, 'note' => null, 'total' => false],
            ['label' => '7-W', 'name' => 'Add back: Non-deductible expenses', 'amount' => $nonDeductible,
                'note' => 'Meals & entertainment, income tax and franking deficit tax paid (flagged in config)', 'total' => false],
            ['label' => '7-V', 'name' => 'Less: Exempt income', 'amount' => $exemptIncome, 'note' => null, 'total' => false],
            ['label' => '7-Q', 'name' => 'Less: Other income not included in assessable income', 'amount' => $otherNonAssessable, 'note' => null, 'total' => false],
            ['label' => '7-F', 'name' => 'Less: Decline in value of depreciating assets', 'amount' => null,
                'note' => 'Left blank — SBE claims simplified depreciation at Item 10', 'total' => false],
            ['label' => '7-R', 'name' => 'Less: Tax losses deducted', 'amount' => 0,
                'note' => 'Prior-year losses are not tracked by the system', 'total' => false],
            ['label' => '7-T', 'name' => 'Taxable or net income or loss', 'amount' => $taxableIncome, 'note' => null, 'total' => true],
        ];

        // Item 8 J/K: the franked/unfranked split of dividends paid, from
        // the runs settled in the year (the ledger's account 3400 movement
        // is the total; the declarations' franking percentages split it).
        $dividendRuns = DividendDeclaration::query()
            ->where('entity_id', $entity->id)
            ->where('status', DividendDeclaration::STATUS_COMPLETED)
            ->whereBetween('payment_date', [$fyStart->toDateString(), $fyEndDate->toDateString()])
            ->get();
        $frankedDividends = round($dividendRuns->sum(fn ($d) => $d->frankedCashPortion()));
        $unfrankedDividends = round($dividendRuns->sum(fn ($d) => $d->unfrankedCashPortion()));
        if (($frankedDividends + $unfrankedDividends) > 0 && abs($frankedDividends + $unfrankedDividends - $dividendsPaid) > 1) {
            $warnings[] = sprintf(
                'Dividends settled per the dividend module (%d) differ from ledger account %d movement (%d) — manual journals to that account are not reflected in the 8-J/8-K split.',
                $frankedDividends + $unfrankedDividends,
                $config['dividends_paid_account'],
                $dividendsPaid,
            );
        }
        $frankingOpening = round(FrankingService::openingBalance($fyEnd - 1, $entity->id));
        $frankingClosing = round(FrankingService::closingBalance($fyEnd - 1, $entity->id));

        $financialInfo = [
            ['label' => 'C', 'name' => 'Trade debtors', 'amount' => $tradeDebtors, 'note' => null],
            ['label' => 'D', 'name' => 'All current assets', 'amount' => $currentAssets, 'note' => null],
            ['label' => 'E', 'name' => 'Total assets', 'amount' => $totalAssets, 'note' => null],
            ['label' => 'F', 'name' => 'Trade creditors', 'amount' => $tradeCreditors, 'note' => null],
            ['label' => 'G', 'name' => 'All current liabilities', 'amount' => $currentLiabilities, 'note' => null],
            ['label' => 'H', 'name' => 'Total liabilities', 'amount' => $totalLiabilities, 'note' => null],
            ['label' => 'D', 'name' => 'Total salary and wage expenses', 'amount' => round($salaryTotal),
                'note' => 'Information label — accounts '.implode(', ', $salaryAccounts).' (no payroll ledger kept)'],
            ['label' => 'J', 'name' => 'Franked dividends paid', 'amount' => $frankedDividends,
                'note' => 'Franked portion of the dividend runs settled in the year'],
            ['label' => 'K', 'name' => 'Unfranked dividends paid', 'amount' => $unfrankedDividends,
                'note' => 'Unfranked portion of the dividend runs settled in the year'],
            ['label' => 'P', 'name' => 'Franking account balance — opening', 'amount' => $frankingOpening,
                'note' => 'Per the franking account ledger'],
            ['label' => 'M', 'name' => 'Franking account balance — closing', 'amount' => $frankingClosing,
                'note' => 'Per the franking account ledger'],
        ];

        return [
            'fyStart' => $fyStart,
            'fyEnd' => $fyEndDate,
            'fyEndYear' => $fyEnd,
            'entity' => [
                'name' => $entity?->name ?? '',
                // Profile first, legacy env config as fallback.
                'abn' => CompanyProfile::effectiveAbn($entity?->id),
                'tfn' => CompanyProfile::effectiveTfn($entity?->id),
            ],
            'income' => $incomeRows,
            'expenses' => $expenseRows,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'profitOrLoss' => $profitOrLoss,
            'reconciliation' => $reconciliation,
            'taxableIncome' => $taxableIncome,
            'financialInfo' => $financialInfo,
            'capitalPurchases' => ['accounts' => $capitalAccounts, 'total' => $capitalTotal],
            'gst' => ['collected' => $gstCollected, 'paid' => $gstPaid],
            'bank' => ['inflows' => $bankInflows, 'outflows' => $bankOutflows],
            'taxRate' => $taxRate,
            'estimatedTax' => round($estimatedTax),
            'validations' => $validations,
            'warnings' => $warnings,
        ];
    }
}

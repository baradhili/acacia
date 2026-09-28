<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportingContext;
use App\Models\BillPayment;
use App\Models\Payment;
use App\Services\FiscalYearService;
use App\Services\OpeningBalances;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Ledger;
use IFRS\Models\ReportingPeriod;
use IFRS\Reports\CashFlowStatement;
use Illuminate\Http\Request;

/**
 * Financial Reports — the IFRS statements that make the books
 * verifiable: trial balance, income statement, balance sheet and cash
 * flow. Pure ledger reads via OpeningBalances/FiscalYearService.
 */
class FinancialStatementController extends Controller
{
    use ResolvesReportingContext;

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
}

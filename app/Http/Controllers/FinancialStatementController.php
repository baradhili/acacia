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

    /**
     * Signed (debit-positive) movements per account over the period,
     * excluding year-end closing entries, keyed by account id (names
     * are carried along but are not unique across the chart).
     * Unlike pnlAccountRows() these keep their sign, so refunds and
     * reversals read in the right direction once the statement
     * negates them into its convention.
     *
     * @param  list<string>  $accountTypes
     * @return array<int, array{id: int, name: string, movement: float}>
     */
    protected function pnlSignedMovements(array $accountTypes, Carbon $startDate, Carbon $endDate): array
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

            $rows[$account->id] = ['id' => $account->id, 'name' => $account->name, 'movement' => $movement];
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

    /**
     * The indirect-method cash flow statement over the IFRS ledger.
     * Every operating line — the profit breakdown above the
     * net-profit row and the six working-capital movement sections
     * below it — is a signed, selected-period, closure-excluded
     * movement (pnlSignedMovements), so payroll reads as its own
     * line, refunds and reversals keep their direction, the section
     * sums internally to a signed operating total with no residual
     * plug, and custom date ranges are honoured on every line (the
     * package's getSections() balances are FY-to-date regardless of
     * the requested period, which is why the movements are derived
     * here). Zero-movement lines are omitted. Investing, financing
     * and the net-cash footer derive the same way (non-current and
     * equity movements in cash-flow sign), so the whole statement
     * honours the selected period.
     */
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

        // Every operating line is a signed, selected-period,
        // closure-excluded movement (pnlSignedMovements — debit-
        // positive, negated here into statement convention: income
        // positive, expenses and asset growth negative), so the
        // section sums internally and refunds/reversals keep their
        // direction instead of arriving as magnitudes.
        $signed = fn (array $types) => $this->pnlSignedMovements($types, $startDate, $endDate);

        $operating = [];
        foreach ($signed([Account::OPERATING_REVENUE, Account::NON_OPERATING_REVENUE]) as $row) {
            $operating[] = ['account' => ['name' => 'Income — '.$row['name']], 'balance' => round(-$row['movement'], 2)];
        }
        foreach ($signed([Account::DIRECT_EXPENSE]) as $row) {
            $operating[] = ['account' => ['name' => 'Direct costs — '.$row['name']], 'balance' => round(-$row['movement'], 2)];
        }
        foreach ($signed([Account::OPERATING_EXPENSE, Account::OVERHEAD_EXPENSE, Account::OTHER_EXPENSE]) as $row) {
            $operating[] = ['account' => ['name' => 'Operating expenses — '.$row['name']], 'balance' => round(-$row['movement'], 2)];
        }

        $profit = round(array_sum(array_column($operating, 'balance')), 2);
        $operating[] = ['account' => ['name' => 'Net profit for the period'], 'balance' => $profit];

        // The six working-capital movement sections behind the
        // operating total, on the same selected-period basis (the
        // package's getSections() versions are FY-to-date), labelled
        // for the chart this firm keeps: payroll and reimbursement
        // payables are current liabilities; GST and withheld PAYG
        // sit in the taxation control accounts. Balance-sheet
        // movements negate into cash-flow sign — asset growth is a
        // use of cash (the package's own convention).
        $operatingMovements = [
            'Change in receivables' => CashFlowStatement::RECEIVABLES,
            'Change in supplier payables' => CashFlowStatement::PAYABLES,
            'Change in taxation liabilities (GST, PAYG withheld)' => CashFlowStatement::TAXATION,
            'Change in other current assets' => CashFlowStatement::CURRENT_ASSETS,
            'Change in other current liabilities (wages, super, reimbursements)' => CashFlowStatement::CURRENT_LIABILITIES,
            'Change in provisions' => CashFlowStatement::PROVISIONS,
        ];

        $movementTotal = 0.0;
        foreach ($operatingMovements as $label => $section) {
            $movement = round(-1 * array_sum(array_column($signed(config('ifrs')[$section]), 'movement')), 2);
            if (abs($movement) < 0.005) {
                continue;
            }

            $operating[] = ['account' => ['name' => $label], 'balance' => $movement];
            $movementTotal += $movement;
        }

        // Investing, financing and the net cash footer on the same
        // selected-period basis (the package's getSections() results
        // are FY-to-date regardless of the requested period, which
        // would make the footer contradict the operating section on
        // custom ranges): non-current and equity movements negated
        // into cash-flow sign, netted with the operating result.
        $investingTotal = round(-1 * array_sum(array_column($signed(config('ifrs')[CashFlowStatement::NON_CURRENT_ASSETS]), 'movement')), 2);
        $financingTotal = round(-1 * (
            array_sum(array_column($signed(config('ifrs')[CashFlowStatement::NON_CURRENT_LIABILITIES]), 'movement'))
            + array_sum(array_column($signed(config('ifrs')[CashFlowStatement::EQUITY]), 'movement'))
        ), 2);
        $operatingTotal = round($profit + $movementTotal, 2);
        $netCash = round($operatingTotal + $investingTotal + $financingTotal, 2);

        $lines = ['statement' => [
            'operating' => $operating,
            'operatingTotal' => $operatingTotal,
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

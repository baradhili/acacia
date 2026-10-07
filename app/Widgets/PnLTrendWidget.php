<?php

namespace App\Widgets;

use App\Models\FiscalYearClose;
use App\Models\Invoice;
use App\Services\IfrsPosting;
use Arrilot\Widgets\AbstractWidget;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Entity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash-basis monthly trend over the trailing 12 whole calendar
 * months, read from the same source as the cash flow card: the bank
 * accounts' ledger legs, netted per reference family (an undone
 * movement and its -REV mirror contribute their surviving net,
 * attributed to the family's latest movement) and classified by what
 * the family actually is —
 *
 * - revenue: families whose counterpart legs post to revenue
 *   accounts (client receipts and their refunds);
 * - expenses: families whose counterpart legs post to expense
 *   accounts (supplier payments), employee reimbursements, and the
 *   cash paid on payroll (the net-pay journals and super /
 *   PAYG-withholding settlements — their accrual journals never
 *   touch the bank, so the bank families are the only honest source);
 * - neither: everything else that legitimately moves cash without
 *   being P&L — funds introduced or withdrawn, GST and income-tax
 *   settlements, dividends, capital purchases (balance sheet).
 *
 * Unposted payment rows never appear (no bank legs, no P&L here) and
 * employee captures never appear (their journals post against the
 * payable — the reimbursement family carries the cash). Invoiced
 * amounts never count as income. Internal transfers between the
 * books' own bank accounts move no total cash and are excluded.
 * Averages are unweighted means of the twelve monthly buckets.
 */
class PnLTrendWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $data = [];
        $today = Carbon::now();
        $windowStart = $today->copy()->subMonths(11)->startOfMonth();
        $windowEnd = $today->copy()->endOfDay();

        $revenueByMonth = collect();
        $expensesByMonth = collect();

        $entity = IfrsPosting::resolveEntity();
        if ($entity !== null) {
            foreach ($this->classifyBankFamilies($entity, $windowStart, $windowEnd) as [$month, $kind, $amount]) {
                $target = $kind === 'revenue' ? $revenueByMonth : $expensesByMonth;
                $target[$month] = round(($target[$month] ?? 0) + $amount, 2);
            }
        }

        for ($i = 11; $i >= 0; $i--) {
            $monthStart = $today->copy()->subMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $monthKey = $monthStart->format('Y-m');

            $revenue = $revenueByMonth->get($monthKey, 0.0);
            $expenses = $expensesByMonth->get($monthKey, 0.0);

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

    /**
     * The bank-ledger families of the window with their P&L
     * classification: a list of [month, 'revenue'|'expenses', amount]
     * with the amount signed into its bucket (a refunded receipt
     * reduces revenue, a refunded payment reduces expenses). The
     * EntityScope cannot resolve every widget context, so the entity
     * is filtered explicitly (the TaxReportController precedent).
     *
     * @param  Entity  $entity
     * @return Collection<int, array{0: string, 1: string, 2: float}>
     */
    protected function classifyBankFamilies($entity, Carbon $windowStart, Carbon $windowEnd): Collection
    {
        $revenueTypes = [Account::OPERATING_REVENUE, Account::NON_OPERATING_REVENUE];
        $expenseTypes = [
            Account::OPERATING_EXPENSE, Account::DIRECT_EXPENSE,
            Account::OVERHEAD_EXPENSE, Account::OTHER_EXPENSE,
        ];

        // The bank legs of the window — the cash flow card's own
        // source. Belt-and-braces: year-end closings never trade.
        $bankLegs = DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as bank', 'bank.id', '=', 'l.post_account')
            ->where('bank.entity_id', $entity->id)
            ->where('bank.account_type', Account::BANK)
            ->whereBetween('l.posting_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->whereNull('l.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(fn ($query) => $query
                ->whereNull('t.reference')
                ->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
            ->get(['t.id as transaction_id', 't.reference', 'l.post_account', 'l.posting_date', 'l.entry_type', 'l.amount']);

        // Internal transfers — a bank leg on each of two bank
        // accounts — move no total cash: out entirely.
        $internalTransferIds = $bankLegs
            ->filter(fn ($leg) => str_starts_with((string) $leg->reference, 'XFER-'))
            ->groupBy('transaction_id')
            ->filter(fn ($group) => $group->pluck('post_account')->unique()->count() > 1)
            ->keys();

        $liveTransactionIds = $bankLegs
            ->reject(fn ($leg) => $internalTransferIds->contains($leg->transaction_id))
            ->pluck('transaction_id')
            ->unique();

        // Every leg of the live transactions, carrying its account's
        // type — the counterpart legs classify the family.
        $legs = DB::table('ifrs_ledgers as l')
            ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('ifrs_accounts as a', 'a.id', '=', 'l.post_account')
            ->whereIn('l.transaction_id', $liveTransactionIds)
            ->whereNull('l.deleted_at')
            ->get(['l.transaction_id', 't.reference', 'l.post_account', 'l.posting_date', 'l.entry_type', 'l.amount', 'a.account_type']);

        // A run's PAY journal and its REV mirror share the
        // PAYROLL-{id} family; every other family keeps its reference.
        $familyKey = fn ($leg) => $leg->reference !== null && $leg->reference !== ''
            ? preg_replace('/-(PAY|REV)$/', '', $leg->reference)
            : 'txn-'.$leg->transaction_id;

        $classified = collect();
        foreach ($legs->groupBy($familyKey) as $family => $rows) {
            $bankRows = $rows->where('account_type', Account::BANK);
            $bankNet = round((float) $bankRows->sum(
                fn ($row) => $row->entry_type === Balance::DEBIT ? $row->amount : -$row->amount
            ), 2);

            if (abs($bankNet) < 0.005) {
                continue;
            }

            $month = Carbon::parse($bankRows->max('posting_date'))->format('Y-m');

            if (str_starts_with((string) $family, 'PAYROLL-')
                || str_starts_with((string) $family, 'PAYSET-')
                || str_starts_with((string) $family, 'BAS-SETT-PAYG-')
                || str_starts_with((string) $family, 'REIMB-')) {
                // The cash paid on payroll and employee reimbursements —
                // their journals settle liabilities, so the family, not
                // an account type, is the classifier.
                $classified->push([$month, 'expenses', -$bankNet]);
            } elseif ($rows->contains(fn ($row) => in_array($row->account_type, $revenueTypes))) {
                $classified->push([$month, 'revenue', $bankNet]);
            } elseif ($rows->contains(fn ($row) => in_array($row->account_type, $expenseTypes))) {
                $classified->push([$month, 'expenses', -$bankNet]);
            }

            // Everything else — funds movements, GST and income-tax
            // settlements, dividends, capital purchases — moves cash
            // without being P&L, and lands in neither bucket.
        }

        return $classified;
    }
}

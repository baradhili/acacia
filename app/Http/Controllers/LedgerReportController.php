<?php

namespace App\Http\Controllers;

use App\Exports\AccountStatementExport;
use App\Http\Controllers\Concerns\ResolvesReportingContext;
use App\Services\OpeningBalances;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Ledger;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * IFRS ledger introspection: the per-account statement (running
 * balance from the ledger legs, PDF/Excel exports) and the account
 * schedule of transactions that touched an account.
 */
class LedgerReportController extends Controller
{
    use ResolvesReportingContext;

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
            $statementData = $this->buildAccountStatement(Account::findOrFail($accountId), $startDate, $endDate);
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
            $account = Account::findOrFail($accountId);

            // Ledger legs, not line items: the schedule must be scoped to
            // this account's OWN movement. The old line-item query summed
            // every line item of each transaction, leaking the other
            // accounts' legs into this account's totals (a payroll accrual
            // showed the whole item side, wages and withholding included,
            // on the PAYG schedule), and it never saw a journal's
            // main-account leg — the IFRS main account is carried on the
            // transaction, not as a line item — so transactions where the
            // account was only the main account were missed entirely and
            // the journal cards lost their balancing side. The ledger
            // holds every posted leg, main accounts included.
            $entries = Ledger::where('post_account', $account->id)
                ->whereBetween('posting_date', [$startDate, $endDate])
                ->with('transaction')
                ->orderBy('posting_date')
                ->orderBy('id')
                ->get();

            $ownByTransaction = $entries->groupBy('transaction_id');

            // Every leg of each listed transaction, for the card's
            // full-journal view.
            $legsByTransaction = Ledger::whereIn('transaction_id', $ownByTransaction->keys())
                ->orderBy('id')
                ->get()
                ->groupBy('transaction_id');

            $accountNames = $accounts->keyBy('id');

            $scheduleLines = collect();
            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($ownByTransaction->sortBy(fn ($rows) => $rows->first()->posting_date) as $transactionId => $own) {
                $transaction = $own->first()->transaction;

                $debit = (float) $own->where('entry_type', Balance::DEBIT)->sum('amount');
                $credit = (float) $own->where('entry_type', Balance::CREDIT)->sum('amount');

                $totalDebit += $debit;
                $totalCredit += $credit;

                $scheduleLines->push([
                    // The legs were selected by posting_date — the card
                    // shows that date, never the transaction's own.
                    'date' => Carbon::parse($own->first()->posting_date),
                    'transaction_id' => $transactionId,
                    'transaction_type' => class_basename($transaction),
                    'narration' => $transaction->narration ?? '',
                    'reference' => $transaction->reference ?? '',
                    'line_items' => ($legsByTransaction[$transactionId] ?? collect())->map(function ($leg) use ($accountNames) {
                        $legAccount = $accountNames[$leg->post_account] ?? null;

                        return [
                            'account' => ($legAccount?->code ?? '?').' - '.($legAccount?->name ?? 'Unknown'),
                            'debit' => $leg->entry_type === Balance::DEBIT ? (float) $leg->amount : 0.0,
                            'credit' => $leg->entry_type === Balance::CREDIT ? (float) $leg->amount : 0.0,
                        ];
                    })->values(),
                    'debit' => $debit,
                    'credit' => $credit,
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

        $account = Account::findOrFail($accountId);
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

        $account = Account::findOrFail($accountId);
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
}

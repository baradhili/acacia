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
use IFRS\Models\LineItem;
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
}

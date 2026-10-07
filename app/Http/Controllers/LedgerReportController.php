<?php

namespace App\Http\Controllers;

use App\Exports\AccountStatementExport;
use App\Http\Controllers\Concerns\ResolvesReportingContext;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\BillPaymentAllocation;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;
use App\Models\ReimbursementPayment;
use App\Services\OpeningBalances;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use IFRS\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * IFRS ledger introspection: the per-account statement (running
 * balance from the ledger legs, PDF/Excel exports), the account
 * schedule of transactions that touched an account, and the
 * transaction ledger register — every posted leg across all accounts
 * with document links, for auditing the books end to end.
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

    /**
     * The register's base query: ledger legs in posting order with
     * their transactions eager-loaded, optionally scoped to a date
     * window (null = unbounded — the register audits everything).
     */
    protected function registerQuery(?Carbon $startDate, ?Carbon $endDate)
    {
        return Ledger::query()
            ->when($startDate, fn ($query, $date) => $query->where('posting_date', '>=', $date))
            ->when($endDate, fn ($query, $date) => $query->where('posting_date', '<=', $date))
            ->with('transaction')
            ->orderBy('posting_date')
            ->orderBy('id');
    }

    /**
     * One register row per ledger leg: the transaction's reference
     * first, the account the leg posted to, the debit/credit split
     * and the documents linked to the transaction's source record.
     * A multi-leg journal appears once per account it touches.
     */
    protected function registerRow(Ledger $entry, $accounts, $documentsByReference): array
    {
        $transaction = $entry->transaction;
        $reference = $transaction->reference ?? '';
        $account = $accounts[$entry->post_account] ?? null;
        $isDebit = $entry->entry_type === Balance::DEBIT;

        return [
            'reference' => $reference,
            'date' => Carbon::parse($entry->posting_date),
            'type' => config('ifrs.transactions')[$transaction->transaction_type ?? ''] ?? $transaction->transaction_type ?? '',
            'account_code' => $account?->code ?? '?',
            'account_name' => $account?->name ?? __('reports.transaction_register.unknown_account'),
            'debit' => $isDebit ? (float) $entry->amount : 0.0,
            'credit' => $isDebit ? 0.0 : (float) $entry->amount,
            'narration' => $transaction->narration ?? '',
            'documents' => $documentsByReference[$reference] ?? collect(),
        ];
    }

    /**
     * Whole-filtered-set totals for the summary cards and footer: one
     * aggregate query, exact whatever page the screen is showing. The
     * debit and credit totals each sum the movement and must agree
     * with each other — the register's double-entry self-check.
     */
    protected function registerTotals($query): array
    {
        // Aggregates must not carry the base query's eager load — a
        // stdClass row has no transaction relation to hydrate. Three
        // plain aggregates: no raw SQL to keep portable.
        $base = (clone $query)->reorder()->without('transaction');

        return [
            'legs' => (int) (clone $base)->count(),
            'debit' => round((float) (clone $base)->where('entry_type', Balance::DEBIT)->sum('amount'), 2),
            'credit' => round((float) (clone $base)->where('entry_type', Balance::CREDIT)->sum('amount'), 2),
        ];
    }

    /**
     * Documents keyed by the transaction reference they belong to.
     * The first hop is direct: the document's owner is the record
     * whose number the posting paths write into the reference —
     * payments, bill payments, reimbursement payments, invoices,
     * bills and purchase orders all carry their own attachments.
     * The second hop follows the allocations — payments settle
     * invoices and bill payments settle bills, and the document often
     * hangs off the invoice or bill rather than the payment, so a
     * payment's reference also links the documents of everything it
     * settled. Owners without a number, and documents on
     * non-transaction owners (clients, suppliers), have no reference
     * to match and stay out of the register.
     *
     * Pass $references to resolve only those references' documents —
     * the paginated screen path, bounded to one page's rows (the
     * settled owners those references reach load too). Null loads
     * everything: the CSV export path, whose output is the whole map
     * anyway.
     */
    protected function documentsByReference(?Collection $references = null)
    {
        $owners = [
            Payment::class => 'payment_number',
            BillPayment::class => 'payment_number',
            ReimbursementPayment::class => 'payment_number',
            Invoice::class => 'invoice_number',
            Bill::class => 'bill_number',
            PurchaseOrder::class => 'po_number',
        ];

        $numberByOwner = [];
        foreach ($owners as $class => $column) {
            foreach ($class::query()->pluck($column, 'id') as $id => $number) {
                if ($number !== null && $number !== '') {
                    $numberByOwner[$class.'|'.$id] = $number;
                }
            }
        }

        // Scoped mode: the owner ids behind the requested references,
        // so only their allocations and documents load.
        $ownerIdsByClass = null;
        if ($references !== null) {
            $wanted = $references->filter(fn ($reference) => $reference !== null && $reference !== '')->flip();
            $ownerIdsByClass = [];
            foreach ($numberByOwner as $key => $number) {
                if ($wanted->has($number)) {
                    [$class, $id] = explode('|', $key);
                    $ownerIdsByClass[$class][] = $id;
                }
            }
        }

        // Payer owner key => settled owner keys: the allocation tables
        // say which invoices/bills each payment/bill payment settled —
        // scoped to the in-scope payers when references were given.
        $settledOwners = [];
        $paymentLinks = PaymentAllocation::query()
            ->when($ownerIdsByClass !== null, fn ($query) => $query->whereIn('payment_id', $ownerIdsByClass[Payment::class] ?? []))
            ->get(['payment_id', 'invoice_id']);
        foreach ($paymentLinks as $link) {
            $settledOwners[Payment::class.'|'.$link->payment_id][] = Invoice::class.'|'.$link->invoice_id;
        }
        $billPaymentLinks = BillPaymentAllocation::query()
            ->when($ownerIdsByClass !== null, fn ($query) => $query->whereIn('bill_payment_id', $ownerIdsByClass[BillPayment::class] ?? []))
            ->get(['bill_payment_id', 'bill_id']);
        foreach ($billPaymentLinks as $link) {
            $settledOwners[BillPayment::class.'|'.$link->bill_payment_id][] = Bill::class.'|'.$link->bill_id;
        }

        // The documents to load: every owner type unscoped; in scoped
        // mode, the in-scope owners plus the settled owners their
        // payments reach (an invoice's PDF shows on the paying
        // payment's rows even when the invoice's own rows are not on
        // this page).
        $documentsQuery = Document::query();
        if ($ownerIdsByClass === null) {
            $documentsQuery->whereIn('documentable_type', array_keys($owners));
        } else {
            $documentOwnersByClass = $ownerIdsByClass;
            foreach ($settledOwners as $settledKeys) {
                foreach ($settledKeys as $key) {
                    [$class, $id] = explode('|', $key);
                    if (! in_array($id, $documentOwnersByClass[$class] ?? [])) {
                        $documentOwnersByClass[$class][] = $id;
                    }
                }
            }
            $documentsQuery->where(function ($query) use ($documentOwnersByClass) {
                foreach ($documentOwnersByClass as $class => $ids) {
                    $query->orWhere(function ($classQuery) use ($class, $ids) {
                        $classQuery->where('documentable_type', $class)
                            ->whereIn('documentable_id', $ids);
                    });
                }
            });
        }
        $documentsByOwner = $documentsQuery->get()
            ->groupBy(fn ($document) => $document->documentable_type.'|'.$document->documentable_id);

        // Keying each reference's list by document id keeps it
        // duplicate-free — a settled invoice's document shows on the
        // invoice's own rows and on the payment's, never twice on one.
        $byReference = [];
        foreach ($documentsByOwner as $ownerKey => $documents) {
            $reference = $numberByOwner[$ownerKey] ?? null;
            if ($reference !== null) {
                foreach ($documents as $document) {
                    $byReference[$reference][$document->id] = $document;
                }
            }
        }
        foreach ($settledOwners as $payerKey => $settledKeys) {
            $payerReference = $numberByOwner[$payerKey] ?? null;
            if ($payerReference === null) {
                continue;
            }
            foreach ($settledKeys as $settledKey) {
                foreach ($documentsByOwner[$settledKey] ?? [] as $document) {
                    $byReference[$payerReference][$document->id] = $document;
                }
            }
        }

        return collect($byReference)->map(fn ($documents) => collect($documents));
    }

    /**
     * The register's optional date window: null means unbounded — the
     * register defaults to the whole ledger, not the current month,
     * because it exists to audit everything.
     */
    protected function registerDateRange(Request $request): array
    {
        $startDate = $request->get('start_date') ? Carbon::parse($request->start_date)->startOfDay() : null;
        $endDate = $request->get('end_date') ? Carbon::parse($request->end_date)->endOfDay() : null;

        return [$startDate, $endDate];
    }

    /**
     * IFRS Transaction Ledger Register screen (admin-only route):
     * paginated (the ledger grows without bound), with the summary
     * cards and footer carrying the whole filtered set's totals from
     * one aggregate query.
     */
    public function transactionRegister(Request $request)
    {
        [$startDate, $endDate] = $this->registerDateRange($request);
        $query = $this->registerQuery($startDate, $endDate);

        $page = $query->paginate(100)->withQueryString();

        $accounts = Account::orderBy('code')
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        // Only this page's references resolve documents — bounded to
        // the page, not the whole ledger.
        $documentsByReference = $this->documentsByReference(
            $page->getCollection()->map(fn ($entry) => $entry->transaction->reference ?? '')
        );

        $rows = $page->setCollection(
            $page->getCollection()->map(
                fn ($entry) => $this->registerRow($entry, $accounts, $documentsByReference)
            )
        );

        $totals = $this->registerTotals($query);

        return view('reports.transaction-register', [
            'rows' => $rows,
            'totalDebit' => $totals['debit'],
            'totalCredit' => $totals['credit'],
            'totalLegs' => $totals['legs'],
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /**
     * Export the Transaction Ledger Register to CSV — the same rows
     * the screen shows, streamed chunk-by-chunk so memory stays at
     * one chunk whatever the ledger's size (chunk, not lazy()/cursor:
     * the register's posting-date order must survive, and keyset
     * streaming would replace it). Documents as "name absolute-url".
     */
    public function exportTransactionRegisterCsv(Request $request)
    {
        [$startDate, $endDate] = $this->registerDateRange($request);

        $accounts = Account::orderBy('code')
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        // The whole map: the export writes every row, so its document
        // resolution is bounded by the export's own output.
        $documentsByReference = $this->documentsByReference();

        $keys = 'reports.transaction_register';
        $header = [
            __("{$keys}.reference"),
            __("{$keys}.date"),
            __("{$keys}.type"),
            __("{$keys}.account"),
            __("{$keys}.debit"),
            __("{$keys}.credit"),
            __("{$keys}.narration"),
            __("{$keys}.documents"),
        ];

        $scope = collect([$startDate?->format('Ymd'), $endDate?->format('Ymd')])->filter()->implode('-');
        $filename = 'Transaction-Ledger-Register'.($scope !== '' ? "_{$scope}" : '').'.csv';

        return response()->streamDownload(function () use ($header, $startDate, $endDate, $accounts, $documentsByReference) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);

            $this->registerQuery($startDate, $endDate)->chunk(500, function ($entries) use ($out, $accounts, $documentsByReference) {
                foreach ($entries as $entry) {
                    $row = $this->registerRow($entry, $accounts, $documentsByReference);

                    fputcsv($out, [
                        $row['reference'],
                        $row['date']->format('Y-m-d'),
                        $row['type'],
                        $row['account_code'].' - '.$row['account_name'],
                        number_format($row['debit'], 2, '.', ''),
                        number_format($row['credit'], 2, '.', ''),
                        $row['narration'],
                        $row['documents']
                            ->map(fn ($document) => $document->name.' '.route('documents.download', $document))
                            ->implode(' | '),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

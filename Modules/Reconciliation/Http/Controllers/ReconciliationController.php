<?php

namespace Modules\Reconciliation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\IfrsPosting;
use IFRS\Models\Account;
use IFRS\Scopes\EntityScope;
use Illuminate\Http\Request;
use Modules\Reconciliation\Models\BankTransaction;
use Modules\Reconciliation\Services\BankTransferService;
use Modules\Reconciliation\Services\PayrollLiabilityService;
use Modules\Reconciliation\Services\ReconciliationService;
use Modules\Reconciliation\Services\StatementImportService;

/**
 * Bank reconciliation: bank statement rows (imported from a Wise
 * statement download — CSV, MT940 or camt.053 XML, auto-detected) are
 * matched against invoices, payments, bills and ledger entries.
 * The Wise API sync was removed: the statement upload is the only feed.
 */
class ReconciliationController extends Controller
{
    public function __construct(
        private ReconciliationService $reconciliation,
        private BankTransferService $transfers,
        private StatementImportService $statementImport,
        private PayrollLiabilityService $payrollLiabilities,
    ) {}

    public function index()
    {
        $pending = BankTransaction::pending()
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();
        $matched = BankTransaction::matched()
            ->orderByDesc('matched_at')
            ->limit(25)
            ->get();
        $unreconciledLedger = $this->reconciliation->getUnreconciledBankMovements();
        $cashCheck = $this->reconciliation->bankVsBooks();

        $stats = [
            'pending' => $pending->count(),
            'matched' => BankTransaction::matched()->count(),
            'ignored' => BankTransaction::where('status', BankTransaction::STATUS_IGNORED)->count(),
            'in_books' => $unreconciledLedger->count(),
        ];

        return view('reconciliation.index', compact('pending', 'matched', 'unreconciledLedger', 'stats', 'cashCheck'));
    }

    public function import()
    {
        return view('reconciliation.import');
    }

    /**
     * Import an uploaded statement. The format is detected from the
     * content, so the upload only pins the extension to the family the
     * feed produces (.mt940 / .camt / .sta are not MIME types Laravel
     * can guess).
     */
    public function processImport(Request $request)
    {
        $request->validate([
            'statement' => 'required|file|max:10240|extensions:csv,txt,xml,mt940,camt,sta',
        ]);

        $result = $this->statementImport->import($request->file('statement')->getRealPath());

        if (isset($result['error'])) {
            // The CSV legacy path returns prose; the multi-format paths
            // return codes the translator knows.
            $error = match ($result['error']) {
                StatementImportService::ERR_UNREADABLE => __('reconciliation.import.error_unreadable'),
                StatementImportService::ERR_UNRECOGNISED => __('reconciliation.import.error_unrecognised'),
                default => $result['error'],
            };

            return back()->with('error', $error);
        }

        $message = __('reconciliation.import.imported', [
            'count' => $result['imported'],
            'format' => $result['format'],
        ]);
        if ($result['skipped'] > 0) {
            $message .= __('reconciliation.import.skipped', ['count' => $result['skipped']]);
        }
        if (! empty($result['errors'])) {
            $message .= __('reconciliation.import.first_issue', ['issue' => $result['errors'][0]]);
        }

        return redirect()->route('reconciliation.index')->with('success', $message.'.');
    }

    /**
     * Run the auto-matcher over every pending bank transaction
     * (±$0.01 amount, ±3 days, opposite ledger side).
     */
    public function autoMatch()
    {
        $results = $this->reconciliation->autoMatchAll();

        $message = "Auto-matched {$results['matched']} transactions";
        if ($results['unmatched'] > 0) {
            $message .= ", {$results['unmatched']} left pending";
        }

        return redirect()->route('reconciliation.index')->with('success', $message.'.');
    }

    public function ignore(Request $request, BankTransaction $transaction)
    {
        $ok = $this->reconciliation->ignoreTransaction(
            $transaction,
            $request->input('reason') ?: 'Marked as non-business from the reconciliation screen'
        );

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Transaction ignored.'
            : 'Transaction could not be ignored (it may already be matched or ignored).');
    }

    /**
     * The manual match screen for one pending bank line: payment
     * candidates (±14 days to allow for bank lag, amount-close — client
     * payments for money in, supplier payments for money out) plus a
     * reference/counterparty search that ignores the amount for the
     * lines the bank grossed up or split.
     */
    public function matchScreen(Request $request, BankTransaction $transaction)
    {
        if ($transaction->status !== BankTransaction::STATUS_PENDING) {
            return redirect()->route('reconciliation.index')
                ->with('error', 'Only pending transactions can be matched.');
        }

        $search = trim((string) $request->query('q', ''));

        $candidates = $this->reconciliation->getAvailableTransactionsForLinking(
            $transaction,
            null,
            50,
            $search !== '' ? ['days' => 60, 'q' => $search] : ['days' => 14]
        );

        // EntityScope cannot resolve an entity-less account, so the
        // entity is filtered explicitly (the TaxReportController
        // precedent) — no bank accounts to offer when there is no
        // entity at all.
        $entity = IfrsPosting::resolveEntity();
        $bankAccounts = $entity !== null
            ? Account::withoutGlobalScope(EntityScope::class)
                ->where('entity_id', $entity->id)
                ->where('account_type', Account::BANK)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
            : collect();

        // Settle-or-refund-a-liability card: payroll's statutory
        // payables, for money-out and money-in lines alike (empty when
        // Payroll is disabled — the card hides with it).
        $payableAccounts = $entity !== null
            ? $this->payrollLiabilities->settlablePayables($entity)
            : collect();

        return view('reconciliation.match', compact('transaction', 'candidates', 'search', 'bankAccounts', 'payableAccounts'));
    }

    /**
     * Record a pending bank line against a payroll liability (PAYG
     * withholding or super payable): money-out settles it (Dr payable
     * / Cr bank), money-in refunds it — the ATO or a fund returning an
     * over-remitted amount (Dr bank / Cr payable, never Funds
     * Introduced equity). Dated the line's date, matched to the
     * journal's bank leg — the books learn the movement the accrual
     * journals never held.
     */
    public function storeSettlement(Request $request, BankTransaction $transaction)
    {
        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:ifrs_accounts,id'],
            'payable_account_id' => ['required', 'integer', 'exists:ifrs_accounts,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $recorder = $transaction->type === BankTransaction::TYPE_CREDIT ? 'refund' : 'settle';

            $this->payrollLiabilities->{$recorder}(
                $transaction,
                (int) $validated['bank_account_id'],
                (int) $validated['payable_account_id'],
                $validated['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()->route('reconciliation.index')
            ->with('success', __('reconciliation.settlement.posted'));
    }

    /**
     * Record a pending bank line as your own money moving — between
     * two bank accounts the books track, or in from / out to an
     * account the books don't track. Posts the journal (bank pair or
     * Funds Introduced/Withdrawn equity) and matches the line to it
     * in one action. Payment-limit splits each record their own line.
     */
    public function storeTransfer(Request $request, BankTransaction $transaction)
    {
        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:ifrs_accounts,id'],
            'counterpart_account_id' => ['nullable', 'integer', 'exists:ifrs_accounts,id', 'different:bank_account_id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            // Absent means external — a hand-crafted POST may omit the
            // select entirely, and validated() drops absent keys.
            $counterpart = $validated['counterpart_account_id'] ?? null;

            $this->transfers->record(
                $transaction,
                (int) $validated['bank_account_id'],
                $counterpart !== null && $counterpart !== '' ? (int) $counterpart : null,
                $validated['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()->route('reconciliation.index')
            ->with('success', 'Transfer posted and matched — the books now hold the movement.');
    }

    /**
     * Record a manual match and learn from it: the counterparty is
     * remembered so the auto-matcher can pair its future bank lines.
     */
    public function storeMatch(Request $request, BankTransaction $transaction)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:payment,bill_payment,reimbursement_payment,ledger'],
            'target_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $ok = $this->reconciliation->manualOverrideLink(
            $transaction,
            $validated['type'],
            $validated['target_id'],
            $validated['notes'] ?? null
        );

        if (! $ok) {
            return back()->withInput()
                ->with('error', "Could not match to {$validated['type']} #{$validated['target_id']} — it may not exist, it may already be reconciled to another bank line, or this bank line is already matched.");
        }

        return redirect()->route('reconciliation.index')
            ->with('success', "Matched to {$validated['type']} #{$validated['target_id']} — noted for future auto-matching.");
    }

    /**
     * Unlink a matched bank line, returning it to pending.
     */
    public function unmatch(BankTransaction $transaction)
    {
        $ok = $this->reconciliation->unlinkTransaction($transaction);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Transaction unlinked — it is pending again.'
            : 'Transaction could not be unlinked (it may not be matched).');
    }
}

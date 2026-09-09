<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Services\ReconciliationService;
use Illuminate\Http\Request;

/**
 * Bank reconciliation: bank statement rows (imported from a Wise CSV
 * export — transaction-history.csv or the older statement download)
 * are matched against invoices, payments, bills and ledger entries.
 * The Wise API sync was removed: the CSV upload is the only feed.
 */
class ReconciliationController extends Controller
{
    public function __construct(private ReconciliationService $reconciliation) {}

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

        $stats = [
            'pending' => $pending->count(),
            'matched' => BankTransaction::matched()->count(),
            'ignored' => BankTransaction::where('status', BankTransaction::STATUS_IGNORED)->count(),
        ];

        return view('reconciliation.index', compact('pending', 'matched', 'stats'));
    }

    public function import()
    {
        return view('reconciliation.import');
    }

    public function processImport(Request $request)
    {
        $request->validate([
            'wise_csv' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $result = $this->reconciliation->importFromCsv($request->file('wise_csv')->getRealPath());

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        $message = "Imported {$result['imported']} transactions";
        if ($result['skipped'] > 0) {
            $message .= ", skipped {$result['skipped']} (already imported or not completed movements)";
        }
        if (! empty($result['errors'])) {
            $message .= '. First issue: '.$result['errors'][0];
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
}

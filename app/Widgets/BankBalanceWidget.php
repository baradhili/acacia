<?php

namespace App\Widgets;

use Arrilot\Widgets\AbstractWidget;
use Modules\Reconciliation\Models\BankTransaction;

/**
 * Dashboard card for the bank position: credits less debits over
 * every Reconciliation-module BankTransaction (one all() load —
 * no entity or user scoping), plus pending/matched/ignored counts
 * and a per-source breakdown. Registered by CoreNav; the figure
 * is firm-wide, so per-user layout preferences affect placement
 * only, never the numbers.
 */
class BankBalanceWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $bankTransactions = BankTransaction::all();

        $totalCredits = $bankTransactions->where('type', BankTransaction::TYPE_CREDIT)->sum('amount');
        $totalDebits = $bankTransactions->where('type', BankTransaction::TYPE_DEBIT)->sum('amount');
        $balance = $totalCredits - $totalDebits;

        $unreconciled = BankTransaction::where('status', BankTransaction::STATUS_PENDING)->count();
        $matched = BankTransaction::where('status', BankTransaction::STATUS_MATCHED)->count();
        $ignored = BankTransaction::where('status', BankTransaction::STATUS_IGNORED)->count();

        $bySource = $bankTransactions
            ->groupBy('source')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total' => $group->sum('amount'),
            ]);

        return view('widgets.bank_balance', [
            'balance' => $balance,
            'balance_formatted' => number_format($balance, 2),
            'total_credits' => $totalCredits,
            'total_credits_formatted' => number_format($totalCredits, 2),
            'total_debits' => $totalDebits,
            'total_debits_formatted' => number_format($totalDebits, 2),
            'unreconciled_count' => $unreconciled,
            'matched_count' => $matched,
            'ignored_count' => $ignored,
            'by_source' => $bySource,
        ]);
    }
}

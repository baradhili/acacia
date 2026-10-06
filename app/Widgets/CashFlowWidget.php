<?php

namespace App\Widgets;

use App\Models\FiscalYearClose;
use App\Services\IfrsPosting;
use Arrilot\Widgets\AbstractWidget;
use Carbon\Carbon;
use IFRS\Models\Account;
use IFRS\Models\Balance;
use Illuminate\Support\Facades\DB;

/**
 * Cash in and out over the trailing 30 days, read from the bank
 * accounts' own ledger legs — every posted movement that actually
 * hit the bank, whatever posted it: client receipts, supplier
 * payments and reimbursements, payroll's net-pay journals and its
 * super / PAYG-withholding settlements, BAS settlements, dividends,
 * funds introduced or withdrawn. The payment subledgers the widget
 * once read cannot see the journal-settled movements (the same blind
 * spot the P&L trend had), so the ledger is the only honest source
 * for "actual cash flow".
 *
 * Internal transfers between the books' own bank accounts move no
 * total cash and are excluded; a transfer against an external
 * account keeps its one bank leg (money really arrived or left). A
 * reference family nets across its reversals (an undone payment and
 * its -REV mirror contribute their surviving net, attributed to the
 * family's latest movement), so accounting undos never inflate the
 * gross flows. The net flow's percentage change against the prior 30
 * days reports 0% on a zero-net prior period rather than dividing by
 * zero.
 */
class CashFlowWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $today = Carbon::now();
        // Whole calendar days: ledger posting dates carry no time, so a
        // boundary carrying one would shuffle the boundary day's
        // movements between windows by time-of-day. The lower boundary
        // day belongs to the current period.
        $thirtyDaysAgo = $today->copy()->subDays(30)->startOfDay();
        $sixtyDaysAgo = $today->copy()->subDays(60)->startOfDay();

        $inflows = 0.0;
        $outflows = 0.0;
        $previousInflows = 0.0;
        $previousOutflows = 0.0;

        $entity = IfrsPosting::resolveEntity();
        if ($entity !== null) {
            $legs = DB::table('ifrs_ledgers as l')
                ->join('ifrs_transactions as t', 't.id', '=', 'l.transaction_id')
                ->join('ifrs_accounts as bank', 'bank.id', '=', 'l.post_account')
                ->where('bank.entity_id', $entity->id)
                ->where('bank.account_type', Account::BANK)
                ->whereBetween('l.posting_date', [$sixtyDaysAgo->toDateString(), $today->toDateString()])
                ->whereNull('l.deleted_at')
                ->whereNull('t.deleted_at')
                // Belt-and-braces: year-end closing entries never touch
                // the bank, and their Retained Earnings main account
                // already drops them.
                ->where(fn ($query) => $query
                    ->whereNull('t.reference')
                    ->orWhereNot('t.reference', 'like', FiscalYearClose::CLOSING_REFERENCE_PREFIX.'%'))
                ->get(['t.id as transaction_id', 't.reference', 'l.post_account', 'l.posting_date', 'l.entry_type', 'l.amount']);

            // Internal transfers: bank legs on two distinct accounts of
            // the books — cash moved between the company's own pockets,
            // not in or out.
            $internalTransferIds = $legs
                ->filter(fn ($leg) => str_starts_with((string) $leg->reference, 'XFER-'))
                ->groupBy('transaction_id')
                ->filter(fn ($group) => $group->pluck('post_account')->unique()->count() > 1)
                ->keys();

            foreach ($legs->reject(fn ($leg) => $internalTransferIds->contains($leg->transaction_id))
                ->groupBy(fn ($leg) => $leg->reference !== null && $leg->reference !== ''
                    ? preg_replace('/-REV$/', '', $leg->reference)
                    : 'txn-'.$leg->transaction_id) as $group
            ) {
                $net = round((float) $group->sum(
                    fn ($leg) => $leg->entry_type === Balance::DEBIT ? $leg->amount : -$leg->amount
                ), 2);

                if (abs($net) < 0.005) {
                    continue;
                }

                // The family's latest movement decides which window the
                // surviving net belongs to.
                $date = Carbon::parse($group->max('posting_date'))->startOfDay();
                if ($date >= $thirtyDaysAgo) {
                    $net > 0 ? $inflows += $net : $outflows += -$net;
                } else {
                    $net > 0 ? $previousInflows += $net : $previousOutflows += -$net;
                }
            }

            $inflows = round($inflows, 2);
            $outflows = round($outflows, 2);
            $previousInflows = round($previousInflows, 2);
            $previousOutflows = round($previousOutflows, 2);
        }

        $netCashFlow = $inflows - $outflows;
        $previousNet = $previousInflows - $previousOutflows;
        $change = $previousNet != 0 ? (($netCashFlow - $previousNet) / abs($previousNet)) * 100 : 0;

        return view('widgets.cash_flow', [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'net_flow' => $netCashFlow,
            'change_percent' => round($change, 1),
            'inflows_formatted' => number_format($inflows, 2),
            'outflows_formatted' => number_format($outflows, 2),
            'net_flow_formatted' => number_format($netCashFlow, 2),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Prepayment;
use App\Models\PrepaymentAmortisation;
use App\Services\PrepaymentService;
use Illuminate\Http\Request;

/**
 * Review and drive prepaid service-contract schedules: see the funded
 * amount, the posted and planned monthly amortisation entries, run the
 * runner for one prepayment on demand, reverse a month, or void the
 * whole schedule. Admin/accountant only — these actions post to the
 * IFRS ledger.
 */
class PrepaymentController extends Controller
{
    public function index()
    {
        $prepayments = Prepayment::with(['assetAccount', 'expenseAccount', 'billPayment', 'billItem.bill'])
            ->orderByDesc('created_at')
            ->get();

        $totals = [
            'funded' => round($prepayments->where('status', '!=', Prepayment::STATUS_VOID)->sum('total_amount'), 2),
            'amortised' => round($prepayments->sum(fn ($p) => $p->amortisedAmount()), 2),
            'remaining' => round($prepayments->where('status', '!=', Prepayment::STATUS_VOID)->sum(fn ($p) => $p->remainingAmount()), 2),
        ];

        return view('prepayments.index', compact('prepayments', 'totals'));
    }

    public function show(Prepayment $prepayment)
    {
        $prepayment->load(['assetAccount', 'expenseAccount', 'billPayment', 'billItem.bill', 'amortisations']);

        return view('prepayments.show', [
            'prepayment' => $prepayment,
            'schedule' => PrepaymentService::scheduleWithPlanned($prepayment),
        ]);
    }

    /**
     * Run the amortisation runner for one prepayment up to today.
     */
    public function runNow(Request $request, Prepayment $prepayment)
    {
        try {
            $count = PrepaymentService::amortise($prepayment);
            $message = $count > 0
                ? "Posted {$count} amortisation month(s)."
                : 'Nothing was due — the schedule is up to date (or awaiting future months).';
            return redirect()->route('prepayments.show', $prepayment)->with('success', $message);
        } catch (\Throwable $e) {
            return redirect()->route('prepayments.show', $prepayment)
                ->with('error', 'Amortisation failed: ' . $e->getMessage());
        }
    }

    public function reverseAmortisation(Request $request, PrepaymentAmortisation $amortisation)
    {
        $reversalId = PrepaymentService::reverseAmortisation($amortisation);
        if (!$reversalId) {
            return back()->with('error', 'Only posted, unreversed entries can be reversed.');
        }

        return redirect()->route('prepayments.show', $amortisation->prepayment_id)
            ->with('success', 'Reversed the amortisation entry for ' . $amortisation->period_date->format('M Y') . '.');
    }

    /**
     * Void the schedule: every posted month is reversed with a mirrored
     * entry (the ledger keeps both legs for audit) and no further
     * entries will post.
     */
    public function void(Request $request, Prepayment $prepayment)
    {
        if ($prepayment->status === Prepayment::STATUS_VOID) {
            return back()->with('error', 'This schedule is already void.');
        }

        $reversed = 0;
        foreach ($prepayment->amortisations()->whereNull('reversed_at')->get() as $entry) {
            if (PrepaymentService::reverseAmortisation($entry)) {
                $reversed++;
            }
        }

        $prepayment->update(['status' => Prepayment::STATUS_VOID]);

        return redirect()->route('prepayments.show', $prepayment)
            ->with('success', "Schedule voided — {$reversed} posted month(s) reversed in the ledger.");
    }
}

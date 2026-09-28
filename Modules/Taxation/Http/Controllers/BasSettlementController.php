<?php

namespace Modules\Taxation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Taxation\Models\BasSettlement;
use Modules\Taxation\Models\PaygInstalmentAccrual;
use Modules\Taxation\Services\BasSettlementService;
use Modules\Taxation\Services\PaygInstalmentService;

/**
 * BAS settlements — recording the ATO payment (or refund) that nets
 * GST Payable against GST Receivable and clears both accounts. Admin
 * or accountant only (route middleware); the netting and journal
 * posting live in BasSettlementService, shared with nothing else.
 */
class BasSettlementController extends Controller
{
    public function __construct(protected BasSettlementService $service) {}

    public function index(Request $request)
    {
        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        $asAt = $request->get('as_at') ? Carbon::parse($request->get('as_at')) : null;
        $quarterEnds = $this->service->quarterEnds($entity);

        // One as-at for the whole screen: the requested date, else the
        // latest completed quarter end (falling back to today before
        // any quarter has completed), so the positions shown, the date
        // filter and the settle form's default can never disagree.
        $effectiveAsAt = $asAt ?? ($quarterEnds !== [] ? last($quarterEnds)['end'] : now());

        // The PAYG-I accrual card's quarter: separately pickable (the
        // accrual covers one exact quarter), defaulting to the same
        // latest completed quarter end — and falling back to it when the
        // requested date is not a BAS quarter end. Hidden before any
        // quarter has completed — there is nothing to accrue yet.
        $paygi = app(PaygInstalmentService::class);
        $paygiEnd = $request->get('paygi_quarter') ? Carbon::parse($request->get('paygi_quarter')) : null;
        if ($paygiEnd === null || $paygi->quarterFor($entity, $paygiEnd) === null) {
            $paygiEnd = $quarterEnds !== [] ? last($quarterEnds)['end'] : null;
        }

        return view('taxation.settlements', [
            'positions' => $this->service->positions($effectiveAsAt),
            'priorGstCarry' => $this->service->priorYearsCarry($effectiveAsAt),
            'positionAsAt' => $effectiveAsAt->toDateString(),
            'quarterEnds' => $quarterEnds,
            'defaultAsAt' => $effectiveAsAt->toDateString(),
            'settlements' => BasSettlement::where('entity_id', $entity->id)
                ->orderByDesc('as_at')
                ->get(),
            'paygiEstimate' => $paygiEnd ? $paygi->estimate($entity, $paygiEnd) : null,
            'paygiAccruals' => PaygInstalmentAccrual::where('entity_id', $entity->id)
                ->orderByDesc('period_end')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(BasSettlement::TYPES)],
            'as_at' => ['required', 'date'],
            'settled_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $settlement = $this->service->settle($validated);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('bas-settlements.index')->with('error', $e->getMessage());
        }

        return redirect()->route('bas-settlements.index')->with('success', sprintf(
            'Settlement recorded — %s to %s: payable $%s, receivable $%s, %s $%s.',
            BasSettlement::typeLabel($settlement->type),
            $settlement->as_at->format('d M Y'),
            number_format($settlement->gst_payable, 2),
            number_format($settlement->gst_receivable, 2),
            $settlement->direction === BasSettlement::DIRECTION_PAY ? 'paid to ATO' : 'refunded by ATO',
            number_format($settlement->bank_amount, 2),
        ));
    }

    public function reverse(BasSettlement $settlement)
    {
        try {
            $this->service->reverse($settlement);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('bas-settlements.index')->with('error', $e->getMessage());
        }

        return redirect()->route('bas-settlements.index')
            ->with('success', 'Settlement reversed — the GST balances have been restored.');
    }
}

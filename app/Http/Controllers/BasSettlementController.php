<?php

namespace App\Http\Controllers;

use App\Models\BasSettlement;
use App\Services\BasSettlementService;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use Illuminate\Http\Request;

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

        return view('bas-settlements.index', [
            'position' => $this->service->position($asAt),
            'positionAsAt' => ($asAt ?? now())->toDateString(),
            'quarterEnds' => $quarterEnds,
            'defaultAsAt' => $quarterEnds !== []
                ? last($quarterEnds)['end']->toDateString()
                : now()->toDateString(),
            'settlements' => BasSettlement::where('entity_id', $entity->id)
                ->orderByDesc('as_at')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
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
            'Settlement recorded — GST to %s: payable $%s, receivable $%s, %s $%s.',
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

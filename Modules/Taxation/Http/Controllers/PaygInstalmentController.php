<?php

namespace Modules\Taxation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Taxation\Models\PaygInstalmentAccrual;
use Modules\Taxation\Services\PaygInstalmentService;

/**
 * PAYG instalment accruals — recording (and reversing) the quarterly
 * estimate journal that raises the income tax liability the
 * payg_instalment BAS settlement later nets. The accrual card lives on
 * the BAS settlements screen (BasSettlementController::index renders
 * it); admin or accountant only (route middleware), like settlements.
 */
class PaygInstalmentController extends Controller
{
    public function __construct(protected PaygInstalmentService $service) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'period_end' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $accrual = $this->service->accrue($validated);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('bas-settlements.index')->with('error', $e->getMessage());
        }

        return redirect()->route('bas-settlements.index')->with('success', sprintf(
            'Accrual recorded — %s: instalment income $%s × %s%% = $%s (Dr income tax expense / Cr income tax payable).',
            $accrual->label(),
            number_format($accrual->instalment_income, 2),
            rtrim(rtrim(number_format($accrual->rate, 2), '0'), '.'),
            number_format($accrual->amount, 2),
        ));
    }

    public function reverse(PaygInstalmentAccrual $accrual)
    {
        try {
            $this->service->reverse($accrual);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('bas-settlements.index')->with('error', $e->getMessage());
        }

        return redirect()->route('bas-settlements.index')
            ->with('success', 'Accrual reversed — '.$accrual->label().' can be accrued again.');
    }
}

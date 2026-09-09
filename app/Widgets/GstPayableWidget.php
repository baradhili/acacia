<?php

namespace App\Widgets;

use App\Services\BasSettlementService;
use App\Services\IfrsPosting;
use Arrilot\Widgets\AbstractWidget;

/**
 * The unlodged GST position straight off the ledger — the same
 * balances the BAS settlement screen nets (GST Payable credit vs GST
 * Receivable debit) — so both sides show and the figure stays correct
 * as payments post, instead of the old invoice-based estimate that
 * only ever approximated the payable side.
 */
class GstPayableWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $entity = IfrsPosting::resolveEntity();

        $position = $entity !== null
            ? app(BasSettlementService::class)->position()
            : ['payable' => 0.0, 'receivable' => 0.0, 'net' => 0.0];

        return view('widgets.gst_payable', [
            'payable' => $position['payable'],
            'receivable' => $position['receivable'],
            'net' => $position['net'],
        ]);
    }
}

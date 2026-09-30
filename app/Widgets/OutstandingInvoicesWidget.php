<?php

namespace App\Widgets;

use App\Models\Invoice;
use Arrilot\Widgets\AbstractWidget;

/**
 * Dashboard card for total receivables: the sum of totals over
 * the outstanding scope (sent, partially paid, overdue) less all
 * payment allocations against those invoices, floored at zero.
 * Registered by CoreNav; the figure is firm-wide — per-user
 * layout preferences affect placement only.
 */
class OutstandingInvoicesWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $total = Invoice::outstanding()->sum('total') - Invoice::outstanding()->get()->sum(function ($invoice) {
            return $invoice->allocations()->sum('amount');
        });

        return view('widgets.outstanding_invoices', [
            'total' => number_format(max(0, $total), 2),
        ]);
    }
}

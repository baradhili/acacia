<?php

namespace App\Widgets;

use App\Models\Invoice;
use Arrilot\Widgets\AbstractWidget;

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

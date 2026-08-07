<?php

namespace App\Widgets;

use App\Models\Invoice;
use Arrilot\Widgets\AbstractWidget;

class GstPayableWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $gstRate = config('australian.gst_rate', 10) / 100;
        
        $totalGst = Invoice::outstanding()->sum('tax_amount');

        return view('widgets.gst_payable', [
            'gst' => number_format(max(0, $totalGst), 2),
        ]);
    }
}

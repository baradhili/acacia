<?php

namespace App\Widgets;

use App\Services\DashboardService;
use Arrilot\Widgets\AbstractWidget;

class OutstandingPOBudgetsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $service = app(DashboardService::class);
        $data = $service->getOutstandingPOBudgetsWidget();

        return view('widgets.outstanding_po_budgets', $data);
    }
}

<?php

namespace App\Widgets;

use App\Services\DashboardService;
use Arrilot\Widgets\AbstractWidget;

class PnLTrendWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $service = app(DashboardService::class);
        $data = $service->getPnLTrendWidget();

        return view('widgets.pnl_trend', $data);
    }
}

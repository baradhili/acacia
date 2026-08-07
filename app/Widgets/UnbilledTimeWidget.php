<?php

namespace App\Widgets;

use App\Services\DashboardService;
use Arrilot\Widgets\AbstractWidget;

class UnbilledTimeWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $service = app(DashboardService::class);
        $data = $service->getUnbilledTimeWidget();

        return view('widgets.unbilled_time', $data);
    }
}

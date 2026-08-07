<?php

namespace App\Widgets;

use App\Services\DashboardService;
use Arrilot\Widgets\AbstractWidget;

class RecentPaymentsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $service = app(DashboardService::class);
        $data = $service->getRecentPaymentsWidget();

        return view('widgets.recent_payments', $data);
    }
}

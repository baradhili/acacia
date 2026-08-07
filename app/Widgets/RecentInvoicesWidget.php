<?php

namespace App\Widgets;

use App\Services\DashboardService;
use Arrilot\Widgets\AbstractWidget;

class RecentInvoicesWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $service = app(DashboardService::class);
        $data = $service->getRecentInvoicesWidget();

        return view('widgets.recent_invoices', $data);
    }
}

<?php

namespace App\Widgets;

use App\Services\DashboardService;
use Arrilot\Widgets\AbstractWidget;

class BankBalanceWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $service = app(DashboardService::class);
        $data = $service->getBankBalanceWidget();

        return view('widgets.bank_balance', $data);
    }
}

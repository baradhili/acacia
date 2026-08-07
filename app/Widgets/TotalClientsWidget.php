<?php

namespace App\Widgets;

use App\Models\Client;
use Arrilot\Widgets\AbstractWidget;

class TotalClientsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $count = Client::count();

        return view('widgets.total_clients', [
            'count' => $count,
        ]);
    }
}

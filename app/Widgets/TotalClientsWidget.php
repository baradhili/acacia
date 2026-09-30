<?php

namespace App\Widgets;

use App\Models\Client;
use Arrilot\Widgets\AbstractWidget;

/**
 * Dashboard card showing the total Client row count (soft-deleted
 * rows excluded by model scoping). Registered by CoreNav; the
 * count is firm-wide — per-user layout preferences affect
 * placement only.
 */
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

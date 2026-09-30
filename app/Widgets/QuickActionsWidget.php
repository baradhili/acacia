<?php

namespace App\Widgets;

use Arrilot\Widgets\AbstractWidget;

/**
 * Static shortcut card (new client, log time, new project) — no
 * data queries. Not registered in CoreNav's dashboard set, so
 * nothing currently renders it.
 */
class QuickActionsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        return view('widgets.quick_actions');
    }
}

<?php

namespace App\Widgets;

use Arrilot\Widgets\AbstractWidget;

class QuickActionsWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        return view('widgets.quick_actions');
    }
}

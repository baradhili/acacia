<?php

namespace App\Widgets;

use Arrilot\Widgets\AbstractWidget;

/**
 * Static welcome card — no data queries. Not registered in
 * CoreNav's dashboard set, so nothing currently renders it.
 */
class WelcomeWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        return view('widgets.welcome');
    }
}

<?php

namespace App\Widgets;

use Arrilot\Widgets\AbstractWidget;

class WelcomeWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        return view('widgets.welcome');
    }
}

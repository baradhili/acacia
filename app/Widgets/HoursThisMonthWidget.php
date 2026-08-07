<?php

namespace App\Widgets;

use App\Models\TimeEntry;
use Arrilot\Widgets\AbstractWidget;
use Carbon\Carbon;

class HoursThisMonthWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $hours = TimeEntry::whereBetween('start_time', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ])->sum('hours');

        return view('widgets.hours_this_month', [
            'hours' => number_format($hours, 1),
        ]);
    }
}

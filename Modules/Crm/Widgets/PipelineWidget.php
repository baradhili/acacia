<?php

namespace Modules\Crm\Widgets;

use Arrilot\Widgets\AbstractWidget;
use Modules\Crm\Models\Lead;

/**
 * Sales pipeline snapshot: open value, weighted forecast, overdue
 * follow-ups, and the newest leads.
 */
class PipelineWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $open = Lead::whereIn('status', Lead::OPEN_STATUSES);

        $leads = Lead::with('owner')
            ->whereIn('status', Lead::OPEN_STATUSES)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        return view('crm.widgets.pipeline', [
            'pipelineValue' => round((float) (clone $open)->sum('estimated_value'), 2),
            'forecast' => round((float) (clone $open)->selectRaw('SUM(estimated_value * probability / 100) as weighted')->value('weighted'), 2),
            'overdue' => (clone $open)->whereDate('next_follow_up', '<', today())->count(),
            'leads' => $leads,
        ]);
    }
}

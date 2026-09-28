<?php

namespace Modules\Practice\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;

/**
 * Project profitability — every project side by side (the landing
 * screen behind the topbar's Project Profitability link) and the
 * per-project breakdown, over approved time only. Reads the core
 * time-tracking subledger; no IFRS dependency.
 */
class ProjectProfitabilityController extends Controller
{
    public function profitabilityIndex()
    {
        $projects = Project::with([
            'client',
            'staffAssignments',
            'timeEntries' => fn ($query) => $query->approved(),
        ])
            ->orderBy('name')
            ->get()
            ->map(function (Project $project) {
                $revenue = $project->timeEntries->where('billable', true)->sum('total');
                $cost = $project->timeEntries->sum('staff_cost');

                return (object) [
                    'project' => $project,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                    'margin' => $revenue > 0 ? (($revenue - $cost) / $revenue) * 100 : 0,
                ];
            });

        return view('practice.profitability-index', compact('projects'));
    }

    public function profitability(Project $project)
    {
        $project->load([
            'client',
            'staffAssignments',
            'timeEntries' => function ($q) {
                $q->approved();
            },
        ]);

        $totalRevenue = $project->timeEntries->where('billable', true)->sum('total');
        $totalCost = $project->timeEntries->sum('staff_cost');
        $profit = $totalRevenue - $totalCost;
        $profitMargin = $totalRevenue > 0 ? ($profit / $totalRevenue) * 100 : 0;

        return view('practice.profitability', compact('project', 'totalRevenue', 'totalCost', 'profit', 'profitMargin'));
    }
}

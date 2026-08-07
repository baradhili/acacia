<?php

namespace App\Widgets;

use App\Models\TimeEntry;
use Arrilot\Widgets\AbstractWidget;

class UnbilledTimeWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $entries = TimeEntry::with('project.client')
            ->where('billable', true)
            ->where('status', 'approved')
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'project_name' => $entry->project?->name ?? 'No Project',
                    'client_name' => $entry->project?->client?->name ?? 'Unknown',
                    'description' => $entry->description,
                    'hours' => $entry->hours,
                    'rate' => $entry->rate ?? $entry->project?->hourly_rate ?? 0,
                    'amount' => $entry->hours * ($entry->rate ?? $entry->project?->hourly_rate ?? 0),
                    'date' => $entry->date?->format('Y-m-d'),
                ];
            })
            ->filter(function ($entry) {
                return $entry['hours'] > 0;
            })
            ->sortByDesc('date')
            ->take(20)
            ->values();

        $totalHours = $entries->sum('hours');
        $totalAmount = $entries->sum('amount');

        return view('widgets.unbilled_time', [
            'entries' => $entries,
            'count' => $entries->count(),
            'total_hours' => round($totalHours, 2),
            'total_amount' => $totalAmount,
            'total_amount_formatted' => number_format($totalAmount, 2),
        ]);
    }
}

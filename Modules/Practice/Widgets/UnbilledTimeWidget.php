<?php

namespace Modules\Practice\Widgets;

use App\Models\TimeEntry;
use Arrilot\Widgets\AbstractWidget;

/**
 * The billable work-in-progress queue: approved, billable time
 * entries no invoice item links yet. Each entry values at its own
 * rate falling back to the project's hourly rate, so the totals are
 * what invoicing everything would raise today; the card renders the
 * newest five of the twenty gathered.
 */
class UnbilledTimeWidget extends AbstractWidget
{
    protected $config = [];

    public function run()
    {
        $entries = TimeEntry::with(['client', 'project.client'])
            ->where('billable', true)
            ->where('status', 'approved')
            ->whereDoesntHave('invoiceItem')
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'project_name' => $entry->project?->name ?? __('widgets.no_project'),
                    'client_name' => $entry->client?->name
                        ?? $entry->project?->client?->name
                        ?? __('widgets.unknown'),
                    'description' => $entry->description,
                    'hours' => $entry->hours,
                    'rate' => $entry->rate ?? $entry->project?->hourly_rate ?? 0,
                    'amount' => $entry->hours * ($entry->rate ?? $entry->project?->hourly_rate ?? 0),
                    'date' => $entry->entry_date?->format('Y-m-d'),
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

        return view('practice.widgets.unbilled_time', [
            'entries' => $entries,
            'count' => $entries->count(),
            'total_hours' => round($totalHours, 2),
            'total_amount' => $totalAmount,
            'total_amount_formatted' => number_format($totalAmount, 2),
        ]);
    }
}

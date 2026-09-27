<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Time Reports — approved time summarised by client, staff, project
 * and the client-facing per-project timesheet. Reads the time tracking
 * subledger only; no IFRS dependency.
 */
class TimeReportController extends Controller
{
    /**
     * Time Reports
     */
    public function timeByClient(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $clientId = $request->get('client_id');

        $query = TimeEntry::with(['client', 'project.client', 'user'])
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->approved();

        if ($clientId) {
            // Entries carry a denormalised client_id (forced from the
            // project when one is set), so this covers both targeted
            // and project-based entries.
            $query->where('client_id', $clientId);
        }

        $timeEntries = $query->get();

        // Group by client
        $byClient = $timeEntries->groupBy(fn ($e) => $e->client_id ?? $e->project?->client?->id ?? 'unassigned')
            ->map(function ($entries, $groupKey) {
                $client = $entries->first()->client?->name
                    ?? $entries->first()->project?->client?->name
                    ?? 'Unassigned';

                return [
                    'client' => $client,
                    'total_hours' => $entries->sum('hours'),
                    'total_amount' => $entries->sum('total'),
                    'billable_hours' => $entries->where('billable', true)->sum('hours'),
                    'entry_count' => $entries->count(),
                ];
            })->sortByDesc('total_hours');

        $clients = Client::orderBy('name')->pluck('name', 'id');

        $totalHours = $byClient->sum('total_hours');
        $totalAmount = $byClient->sum('total_amount');

        return view('reports.time-by-client', compact(
            'byClient', 'clients', 'startDate', 'endDate', 'clientId',
            'totalHours', 'totalAmount'
        ));
    }

    public function timeByStaff(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $userId = $request->get('user_id');

        $query = TimeEntry::with(['user', 'project'])
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->approved();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $timeEntries = $query->get();

        // Group by staff
        $byStaff = $timeEntries->groupBy('user_id')
            ->map(function ($entries, $userId) {
                $user = $entries->first()->user;

                return [
                    'user' => $user,
                    'total_hours' => $entries->sum('hours'),
                    'total_amount' => $entries->sum('total'),
                    'billable_hours' => $entries->where('billable', true)->sum('hours'),
                    'non_billable_hours' => $entries->where('billable', false)->sum('hours'),
                    'entry_count' => $entries->count(),
                ];
            })->sortByDesc('total_hours');

        $staff = User::orderBy('name')->pluck('name', 'id');

        $totalHours = $byStaff->sum('total_hours');
        $totalAmount = $byStaff->sum('total_amount');

        return view('reports.time-by-staff', compact(
            'byStaff', 'staff', 'startDate', 'endDate', 'userId',
            'totalHours', 'totalAmount'
        ));
    }

    public function timeByProject(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $projectId = $request->get('project_id');

        $query = TimeEntry::with(['project.client', 'user'])
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->approved();

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $timeEntries = $query->get();

        // Group by project
        $byProject = $timeEntries->groupBy('project_id')
            ->map(function ($entries, $projectId) {
                $project = $entries->first()->project;

                return [
                    'project' => $project,
                    'client' => $project?->client?->name ?? 'N/A',
                    'total_hours' => $entries->sum('hours'),
                    'total_amount' => $entries->sum('total'),
                    'billable_hours' => $entries->where('billable', true)->sum('hours'),
                    'non_billable_hours' => $entries->where('billable', false)->sum('hours'),
                    'entry_count' => $entries->count(),
                    'budget_hours' => $project?->budget_hours,
                    'utilization' => $project?->budget_hours
                        ? round(($entries->sum('hours') / $project->budget_hours) * 100, 1)
                        : null,
                ];
            })->sortByDesc('total_hours');

        $projects = Project::orderBy('name')->pluck('name', 'id');

        $totalHours = $byProject->sum('total_hours');
        $totalAmount = $byProject->sum('total_amount');

        return view('reports.time-by-project', compact(
            'byProject', 'projects', 'startDate', 'endDate', 'projectId',
            'totalHours', 'totalAmount'
        ));
    }

    /**
     * Client-facing timesheet report for a project: the week-by-week
     * and month-by-month sums clients ask for, per project (filterable
     * to one project or one client's projects). Weeks start Monday
     * (the timesheet grid's convention).
     */
    public function projectTimesheet(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfYear();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfDay();

        $projectId = $request->get('project_id');
        $clientId = $request->get('client_id');

        $entries = TimeEntry::with(['project.client', 'client', 'user'])
            ->whereBetween('entry_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->approved()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->whereNotNull('project_id')
            ->get();

        $byProject = $entries->groupBy('project_id')
            ->map(function ($projectEntries) {
                $byWeek = $projectEntries
                    ->groupBy(fn ($e) => $e->entry_date->copy()->startOfWeek()->format('Y-m-d'))
                    ->map(fn ($weekEntries, $weekStart) => [
                        'label' => Carbon::parse($weekStart)->format('d M Y'),
                        'hours' => (float) $weekEntries->sum('hours'),
                        'amount' => (float) $weekEntries->sum('total'),
                    ])
                    ->sortKeys();

                $byMonth = $projectEntries
                    ->groupBy(fn ($e) => $e->entry_date->format('Y-m'))
                    ->map(fn ($monthEntries, $ym) => [
                        'label' => Carbon::parse($ym.'-01')->format('M Y'),
                        'hours' => (float) $monthEntries->sum('hours'),
                        'amount' => (float) $monthEntries->sum('total'),
                    ])
                    ->sortKeys();

                return [
                    'project' => $projectEntries->first()->project,
                    'by_week' => $byWeek->values(),
                    'by_month' => $byMonth->values(),
                    'total_hours' => (float) $projectEntries->sum('hours'),
                    'total_amount' => (float) $projectEntries->sum('total'),
                ];
            })
            ->sortBy(fn ($row) => $row['project']?->name)
            ->values();

        return view('reports.project-timesheet', [
            'byProject' => $byProject,
            'projects' => Project::orderBy('name')->pluck('name', 'id'),
            'clients' => Client::orderBy('name')->pluck('name', 'id'),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'projectId' => $projectId,
            'clientId' => $clientId,
            'totalHours' => (float) $entries->sum('hours'),
            'totalAmount' => (float) $entries->sum('total'),
        ]);
    }
}

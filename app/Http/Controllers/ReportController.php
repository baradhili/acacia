<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function timeByClient(Request $request)
    {
        $startDate = $request->get('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();

        $endDate = $request->get('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        $clientId = $request->get('client_id');

        $query = TimeEntry::with(['project.client', 'user'])
            ->whereBetween('start_time', [$startDate, $endDate])
            ->approved();

        if ($clientId) {
            $query->whereHas('project', fn($q) => $q->where('client_id', $clientId));
        }

        $timeEntries = $query->get();

        // Group by client
        $byClient = $timeEntries->groupBy(fn($e) => $e->project?->client?->id ?? 'unassigned')
            ->map(function ($entries, $clientId) {
                $client = $entries->first()->project?->client?->name ?? 'Unassigned';
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
            ->whereBetween('start_time', [$startDate, $endDate])
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
            ->whereBetween('start_time', [$startDate, $endDate])
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
}

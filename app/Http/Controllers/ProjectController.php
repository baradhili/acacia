<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStaff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::with('client')
            ->withSum('timeEntries as total_hours', 'hours')
            ->latest()
            ->paginate(15);

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $staff = User::role(['staff', 'accountant', 'admin'])->orderBy('name')->get();
        return view('projects.create', compact('clients', 'staff'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'budget_hours' => 'nullable|numeric|min:0',
            'budget_amount' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,on_hold,completed,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'staff' => 'nullable|array',
            'staff.*.user_id' => 'required|exists:users,id',
            'staff.*.hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $validated['status'] = $validated['status'] ?? Project::STATUS_ACTIVE;

        $project = Project::create($validated);

        // Assign staff if provided
        if (!empty($validated['staff'])) {
            foreach ($validated['staff'] as $staffData) {
                ProjectStaff::create([
                    'project_id' => $project->id,
                    'user_id' => $staffData['user_id'],
                    'hourly_rate' => $staffData['hourly_rate'] ?? null,
                    'is_active' => true,
                ]);
            }
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project created successfully.');
    }

    public function show(Project $project)
    {
        $project->load(['client', 'staffAssignments.user', 'timeEntries' => function ($q) {
            $q->orderBy('start_time', 'desc');
        }]);

        return view('projects.show', compact('project'));
    }

    public function edit(Project $project)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $staff = User::role(['staff', 'accountant', 'admin'])->orderBy('name')->get();
        return view('projects.edit', compact('project', 'clients', 'staff'));
    }

    public function update(Request $request, Project $project)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'budget_hours' => 'nullable|numeric|min:0',
            'budget_amount' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,on_hold,completed,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'staff' => 'nullable|array',
            'staff.*.user_id' => 'required|exists:users,id',
            'staff.*.hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $project->update($validated);

        // Sync staff assignments
        $project->staffAssignments()->delete();
        if (!empty($validated['staff'])) {
            foreach ($validated['staff'] as $staffData) {
                ProjectStaff::create([
                    'project_id' => $project->id,
                    'user_id' => $staffData['user_id'],
                    'hourly_rate' => $staffData['hourly_rate'] ?? null,
                    'is_active' => true,
                ]);
            }
        }

        return redirect()->route('projects.show', $project)
            ->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')
            ->with('success', 'Project deleted successfully.');
    }

    public function assignStaff(Request $request, Project $project)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);

        ProjectStaff::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $validated['user_id']],
            ['hourly_rate' => $validated['hourly_rate'] ?? null]
        );

        return back()->with('success', 'Staff member assigned successfully.');
    }

    public function removeStaff(Project $project, User $user)
    {
        $project->staffAssignments()->where('user_id', $user->id)->delete();

        return back()->with('success', 'Staff member removed from project.');
    }

    public function profitability(Project $project)
    {
        $project->load(['client', 'timeEntries' => function ($q) {
            $q->approved();
        }]);

        $totalRevenue = $project->timeEntries->where('billable', true)->sum('total');
        $totalCost = $project->timeEntries->sum('total');
        $profit = $totalRevenue - $totalCost;
        $profitMargin = $totalRevenue > 0 ? ($profit / $totalRevenue) * 100 : 0;

        return view('projects.profitability', compact('project', 'totalRevenue', 'totalCost', 'profit', 'profitMargin'));
    }
}

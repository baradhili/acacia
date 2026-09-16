<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStaff;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::with(['client', 'purchaseOrder'])
            ->withSum('timeEntries as total_hours', 'hours')
            ->latest()
            ->paginate(15);

        return view('projects.index', compact('projects'));
    }

    public function create(Request $request)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $staff = User::role(['staff', 'accountant', 'admin'])->orderBy('name')->get();

        // Get purchase orders for selected client (via AJAX or pre-selected)
        $clientId = $request->get('client_id');
        $purchaseOrders = collect();
        if ($clientId) {
            $purchaseOrders = PurchaseOrder::where('client_id', $clientId)
                ->whereNull('project_id')
                ->whereIn('status', [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIALLY_USED])
                ->orderBy('po_number')
                ->get();
        }

        return view('projects.create', compact('clients', 'staff', 'purchaseOrders'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'purchase_order_id' => 'required|exists:purchase_orders,id',
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

        // The PO is claimed inside a transaction with a row lock: a
        // concurrent project could otherwise take it between the
        // existence validation and the create. Any revalidation
        // failure inside throws and rolls back, leaving the PO free.
        $project = DB::transaction(function () use ($validated) {
            $this->assertPurchaseOrderFitsClient($validated, lock: true);

            return Project::create($validated);
        });

        // Assign staff if provided
        if (! empty($validated['staff'])) {
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
        $project->load(['client', 'purchaseOrder', 'staffAssignments.user', 'timeEntries' => function ($q) {
            $q->orderBy('entry_date', 'desc')->orderByDesc('id');
        }]);

        return view('projects.show', compact('project'));
    }

    public function edit(Project $project)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $staff = User::role(['staff', 'accountant', 'admin'])->orderBy('name')->get();

        // Get available purchase orders for the project's client: other
        // projects' POs are excluded, unlinked ones must still be open,
        // and the project's own PO stays selectable whatever its status.
        $purchaseOrders = PurchaseOrder::where('client_id', $project->client_id)
            ->where(function ($query) use ($project) {
                $query->where(function ($q) {
                    $q->whereNull('project_id')
                        ->whereIn('status', [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIALLY_USED]);
                })->orWhere('project_id', $project->id);
            })
            ->orderBy('po_number')
            ->get();

        return view('projects.edit', compact('project', 'clients', 'staff', 'purchaseOrders'));
    }

    public function update(Request $request, Project $project)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'purchase_order_id' => 'required|exists:purchase_orders,id',
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

        $this->assertPurchaseOrderFitsClient($validated, $project);

        $project->update($validated);

        // Sync staff assignments
        $project->staffAssignments()->delete();
        if (! empty($validated['staff'])) {
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

    /**
     * A project's purchase order must belong to the project's client and
     * not already be linked to another project — the client-filtered PO
     * list on the form is UI-only, so the same constraints are enforced
     * server-side. A newly selected PO must also still be open (or
     * partially used); the project's own PO stays selectable whatever
     * its status, since a consumed budget shouldn't force a relink.
     * Pass the project being updated so its own PO passes, and lock the
     * PO row when claiming it for a new project.
     */
    protected function assertPurchaseOrderFitsClient(array $validated, ?Project $project = null, bool $lock = false): void
    {
        $purchaseOrder = PurchaseOrder::whereKey($validated['purchase_order_id'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        if ((int) $purchaseOrder->client_id !== (int) $validated['client_id']) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'This purchase order belongs to a different client.',
            ]);
        }

        if ($purchaseOrder->project_id
            && (int) $purchaseOrder->project_id !== (int) ($project?->id ?? 0)) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'This purchase order is already linked to another project.',
            ]);
        }

        $isOwnSelection = (int) $purchaseOrder->id === (int) ($project?->purchase_order_id ?? 0);
        if (! $isOwnSelection
            && ! in_array($purchaseOrder->status, [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIALLY_USED])) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'This purchase order is no longer open.',
            ]);
        }
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

    /**
     * Every project's profitability side by side — the landing screen
     * behind the topbar's Project Profitability link; each row drills
     * into the per-project breakdown.
     */
    public function profitabilityIndex()
    {
        $projects = Project::with(['client', 'timeEntries' => fn ($query) => $query->approved()])
            ->orderBy('name')
            ->get()
            ->map(function (Project $project) {
                $revenue = $project->timeEntries->where('billable', true)->sum('total');
                $cost = $project->timeEntries->sum('total');

                return (object) [
                    'project' => $project,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                    'margin' => $revenue > 0 ? (($revenue - $cost) / $revenue) * 100 : 0,
                ];
            });

        return view('projects.profitability-index', compact('projects'));
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

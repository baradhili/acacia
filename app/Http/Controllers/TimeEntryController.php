<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TimeEntryController extends Controller
{
    public function index()
    {
        $timeEntries = TimeEntry::with(['user', 'project', 'purchaseOrder'])
            ->orderBy('start_time', 'desc')
            ->paginate(15);

        return view('time-entries.index', compact('timeEntries'));
    }

    public function create()
    {
        $projects = Project::where('status', 'active')->orderBy('name')->pluck('name', 'id');
        $purchaseOrders = PurchaseOrder::whereNotIn('status', ['cancelled'])
            ->orderBy('po_number')
            ->pluck('po_number', 'id');

        return view('time-entries.create', compact('projects', 'purchaseOrders'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'hours' => 'nullable|numeric|min:0',
            'rate' => 'nullable|numeric|min:0',
            'billable' => 'boolean',
            'description' => 'nullable|string|max:1000',
        ]);

        $validated['user_id'] = Auth::id();
        $validated['billable'] = $validated['billable'] ?? true;

        // Calculate hours if end_time provided
        if (!empty($validated['end_time'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = round($start->floatDiffInHours($end), 2);
        }

        TimeEntry::create($validated);

        return redirect()->route('time-entries.index')
            ->with('success', 'Time entry created successfully.');
    }

    public function show(TimeEntry $timeEntry)
    {
        $timeEntry->load(['user', 'project', 'purchaseOrder', 'approver']);
        return view('time-entries.show', compact('timeEntry'));
    }

    public function edit(TimeEntry $timeEntry)
    {
        // Only allow editing draft entries
        if ($timeEntry->status !== TimeEntry::STATUS_DRAFT) {
            return redirect()->route('time-entries.show', $timeEntry)
                ->with('error', 'Only draft entries can be edited.');
        }

        $projects = Project::where('status', 'active')->orderBy('name')->pluck('name', 'id');
        $purchaseOrders = PurchaseOrder::whereNotIn('status', ['cancelled'])
            ->orderBy('po_number')
            ->pluck('po_number', 'id');

        return view('time-entries.edit', compact('timeEntry', 'projects', 'purchaseOrders'));
    }

    public function update(Request $request, TimeEntry $timeEntry)
    {
        // Only allow editing draft entries
        if ($timeEntry->status !== TimeEntry::STATUS_DRAFT) {
            return redirect()->route('time-entries.show', $timeEntry)
                ->with('error', 'Only draft entries can be edited.');
        }

        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'start_time' => 'required|date',
            'end_time' => 'nullable|date|after:start_time',
            'hours' => 'nullable|numeric|min:0',
            'rate' => 'nullable|numeric|min:0',
            'billable' => 'boolean',
            'description' => 'nullable|string|max:1000',
        ]);

        $validated['billable'] = $validated['billable'] ?? true;

        // Calculate hours if end_time provided
        if (!empty($validated['end_time'])) {
            $start = Carbon::parse($validated['start_time']);
            $end = Carbon::parse($validated['end_time']);
            $validated['hours'] = round($start->floatDiffInHours($end), 2);
        }

        $timeEntry->update($validated);

        return redirect()->route('time-entries.show', $timeEntry)
            ->with('success', 'Time entry updated successfully.');
    }

    public function destroy(TimeEntry $timeEntry)
    {
        // Only allow deleting draft entries
        if ($timeEntry->status !== TimeEntry::STATUS_DRAFT) {
            return redirect()->route('time-entries.index')
                ->with('error', 'Only draft entries can be deleted.');
        }

        $timeEntry->delete();

        return redirect()->route('time-entries.index')
            ->with('success', 'Time entry deleted successfully.');
    }

    public function submit(TimeEntry $timeEntry)
    {
        if ($timeEntry->status !== TimeEntry::STATUS_DRAFT) {
            return back()->with('error', 'Only draft entries can be submitted.');
        }

        $timeEntry->submit();

        return back()->with('success', 'Time entry submitted for approval.');
    }

    public function approve(TimeEntry $timeEntry)
    {
        if ($timeEntry->status !== TimeEntry::STATUS_SUBMITTED) {
            return back()->with('error', 'Only submitted entries can be approved.');
        }

        $timeEntry->approve(Auth::id());

        // Update PO used amount if linked
        if ($timeEntry->purchase_order_id) {
            $timeEntry->purchaseOrder->recalculateUsedAmount();
        }

        return back()->with('success', 'Time entry approved.');
    }

    public function reject(Request $request, TimeEntry $timeEntry)
    {
        if ($timeEntry->status !== TimeEntry::STATUS_SUBMITTED) {
            return back()->with('error', 'Only submitted entries can be rejected.');
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $timeEntry->reject(Auth::id(), $request->reason);

        return back()->with('success', 'Time entry rejected.');
    }

    public function weekly(Request $request)
    {
        $weekStart = $request->get('week')
            ? Carbon::parse($request->week)->startOfWeek()
            : Carbon::now()->startOfWeek();

        $weekEnd = $weekStart->copy()->endOfWeek();

        $userId = $request->get('user_id', Auth::id());

        $timeEntries = TimeEntry::where('user_id', $userId)
            ->whereBetween('start_time', [$weekStart, $weekEnd])
            ->with(['project', 'purchaseOrder'])
            ->orderBy('start_time')
            ->get();

        // Group by day
        $byDay = $timeEntries->groupBy(fn($e) => $e->start_time->format('Y-m-d'));

        return view('time-entries.weekly', compact('timeEntries', 'byDay', 'weekStart', 'weekEnd', 'userId'));
    }

    public function monthly(Request $request)
    {
        $month = $request->get('month')
            ? Carbon::parse($request->month)->startOfMonth()
            : Carbon::now()->startOfMonth();

        $monthEnd = $month->copy()->endOfMonth();

        $userId = $request->get('user_id', Auth::id());

        $timeEntries = TimeEntry::where('user_id', $userId)
            ->whereBetween('start_time', [$month, $monthEnd])
            ->with(['project', 'purchaseOrder'])
            ->orderBy('start_time')
            ->get();

        // Group by day
        $byDay = $timeEntries->groupBy(fn($e) => $e->start_time->format('Y-m-d'));

        // Summary stats
        $totalHours = $timeEntries->sum('hours');
        $totalBillable = $timeEntries->where('billable', true)->sum('hours');

        return view('time-entries.monthly', compact('timeEntries', 'byDay', 'month', 'monthEnd', 'userId', 'totalHours', 'totalBillable'));
    }
}

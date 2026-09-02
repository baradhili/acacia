<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Models\TimeEntryBreak;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TimeEntryController extends Controller
{
    public function index()
    {
        $timeEntries = TimeEntry::with(['user', 'client', 'project', 'purchaseOrder'])
            ->orderBy('entry_date', 'desc')
            ->orderByDesc('id')
            ->paginate(15);

        return view('time-entries.index', compact('timeEntries'));
    }

    public function create()
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $projects = Project::where('status', 'active')->orderBy('name')->get();
        $purchaseOrders = PurchaseOrder::whereNotIn('status', ['cancelled'])
            ->orderBy('po_number')
            ->pluck('po_number', 'id');

        return view('time-entries.create', compact('clients', 'projects', 'purchaseOrders'));
    }

    /**
     * Entry shape: a date plus hours is the default. Optionally a start
     * and end time for that date (times are HH:MM), in which case hours
     * are derived as (end − start − breaks). Breaks are zero or more
     * HH:MM pairs inside the timed span.
     */
    public function store(Request $request)
    {
        $validated = $this->validateEntry($request);

        DB::beginTransaction();
        try {
            $entry = TimeEntry::create(
                ['user_id' => Auth::id()] + $this->entryPayload($validated)
            );
            $this->syncBreaks($entry, $validated['breaks'] ?? []);
            $entry->recalculateHours();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error creating time entry: ' . $e->getMessage());
        }

        return redirect()->route('time-entries.index')
            ->with('success', 'Time entry created successfully.');
    }

    public function show(TimeEntry $timeEntry)
    {
        $timeEntry->load(['user', 'client', 'project', 'purchaseOrder', 'approver', 'breaks']);
        return view('time-entries.show', compact('timeEntry'));
    }

    public function edit(TimeEntry $timeEntry)
    {
        // Only allow editing draft entries
        if ($timeEntry->status !== TimeEntry::STATUS_DRAFT) {
            return redirect()->route('time-entries.show', $timeEntry)
                ->with('error', 'Only draft entries can be edited.');
        }

        $clients = Client::orderBy('name')->pluck('name', 'id');
        $projects = Project::where('status', 'active')->orderBy('name')->get();
        $purchaseOrders = PurchaseOrder::whereNotIn('status', ['cancelled'])
            ->orderBy('po_number')
            ->pluck('po_number', 'id');

        $timeEntry->load('breaks');

        return view('time-entries.edit', compact('timeEntry', 'clients', 'projects', 'purchaseOrders'));
    }

    public function update(Request $request, TimeEntry $timeEntry)
    {
        // Only allow editing draft entries
        if ($timeEntry->status !== TimeEntry::STATUS_DRAFT) {
            return redirect()->route('time-entries.show', $timeEntry)
                ->with('error', 'Only draft entries can be edited.');
        }

        $validated = $this->validateEntry($request);

        DB::beginTransaction();
        try {
            $timeEntry->update($this->entryPayload($validated));
            $this->syncBreaks($timeEntry, $validated['breaks'] ?? []);
            $timeEntry->recalculateHours();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error updating time entry: ' . $e->getMessage());
        }

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
        // PO used_amount is recalculated automatically via TimeEntryObserver

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

    /**
     * Shared validation for store/update. Times and breaks are optional
     * HH:MM values on the entry date; hours are required unless times
     * are given (they are then derived server-side).
     */
    protected function validateEntry(Request $request): array
    {
        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'entry_date' => 'required|date',
            'start_time' => 'required_with:end_time|nullable|date_format:H:i',
            'end_time' => 'required_with:start_time|nullable|date_format:H:i|after:start_time',
            'hours' => 'required_without:start_time|nullable|numeric|min:0|max:24',
            'rate' => 'nullable|numeric|min:0',
            'billable' => 'boolean',
            'description' => 'nullable|string|max:1000',
            'breaks' => 'nullable|array',
            'breaks.*.start' => 'required_with:breaks.*.end|nullable|date_format:H:i',
            'breaks.*.end' => 'required_with:breaks.*.start|nullable|date_format:H:i|after:breaks.*.start',
        ], [
            'hours.required_without' => 'Enter the hours worked, or fill in start and end times.',
            'start_time.required_with' => 'Start time is required when an end time is given.',
            'end_time.required_with' => 'End time is required when a start time is given.',
        ]);

        // Drop half-entered break rows before the cross-field checks.
        $validated['breaks'] = array_values(array_filter(
            $validated['breaks'] ?? [],
            fn ($b) => !empty($b['start']) && !empty($b['end'])
        ));

        if (!empty($validated['breaks'])) {
            if (empty($validated['start_time']) || empty($validated['end_time'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'breaks' => 'Breaks can only be recorded on entries with start and end times.',
                ]);
            }

            foreach ($validated['breaks'] as $i => $break) {
                if ($break['start'] < $validated['start_time'] || $break['end'] > $validated['end_time']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'breaks' => "Break " . ($i + 1) . " must fall within the entry's start and end times.",
                    ]);
                }
            }

            // Sorted, any break starting before the previous one ends overlaps.
            $sorted = $validated['breaks'];
            usort($sorted, fn ($a, $b) => strcmp($a['start'], $b['start']));
            for ($i = 1; $i < count($sorted); $i++) {
                if ($sorted[$i]['start'] < $sorted[$i - 1]['end']) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'breaks' => 'Breaks cannot overlap each other.',
                    ]);
                }
            }
        }

        return $validated;
    }

    /**
     * Model payload from validated input: HH:MM times become datetimes on
     * the entry date; timed entries take a provisional span as hours (the
     * final figure lands after breaks sync via recalculateHours()).
     */
    protected function entryPayload(array $validated): array
    {
        $date = Carbon::parse($validated['entry_date'])->startOfDay();

        $payload = [
            'client_id' => $validated['client_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'purchase_order_id' => $validated['purchase_order_id'] ?? null,
            'entry_date' => $validated['entry_date'],
            'start_time' => null,
            'end_time' => null,
            'hours' => null,
            'rate' => $validated['rate'] ?? null,
            'billable' => $validated['billable'] ?? true,
            'description' => $validated['description'] ?? null,
        ];

        if (!empty($validated['start_time']) && !empty($validated['end_time'])) {
            $payload['start_time'] = $date->copy()->setTimeFromTimeString($validated['start_time']);
            $payload['end_time'] = $date->copy()->setTimeFromTimeString($validated['end_time']);
            $payload['hours'] = round($payload['start_time']->floatDiffInHours($payload['end_time']), 2);
        } else {
            $payload['hours'] = $validated['hours'];
        }

        return $payload;
    }

    /**
     * Replace the entry's break rows (drafts only ever hit this path).
     */
    protected function syncBreaks(TimeEntry $entry, array $breaks): void
    {
        $entry->breaks()->delete();

        foreach ($breaks as $break) {
            TimeEntryBreak::create([
                'time_entry_id' => $entry->id,
                'start_time' => $break['start'],
                'end_time' => $break['end'],
            ]);
        }
    }

    public function weekly(Request $request)
    {
        $weekStart = $request->get('week')
            ? Carbon::parse($request->week)->startOfWeek()
            : Carbon::now()->startOfWeek();

        $weekEnd = $weekStart->copy()->endOfWeek();

        $userId = $request->get('user_id', Auth::id());

        $timeEntries = TimeEntry::where('user_id', $userId)
            ->whereBetween('entry_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->with(['client', 'project', 'purchaseOrder'])
            ->orderBy('entry_date')
            ->orderBy('start_time')
            ->get();

        // Group by day
        $byDay = $timeEntries->groupBy(fn($e) => $e->entry_date->format('Y-m-d'));

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
            ->whereBetween('entry_date', [$month->toDateString(), $monthEnd->toDateString()])
            ->with(['client', 'project', 'purchaseOrder'])
            ->orderBy('entry_date')
            ->orderBy('start_time')
            ->get();

        // Group by day
        $byDay = $timeEntries->groupBy(fn($e) => $e->entry_date->format('Y-m-d'));

        // Summary stats
        $totalHours = $timeEntries->sum('hours');
        $totalBillable = $timeEntries->where('billable', true)->sum('hours');

        return view('time-entries.monthly', compact('timeEntries', 'byDay', 'month', 'monthEnd', 'userId', 'totalHours', 'totalBillable'));
    }
}

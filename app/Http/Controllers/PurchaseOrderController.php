<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index()
    {
        $purchaseOrders = PurchaseOrder::with(['client', 'project'])
            ->latest()
            ->paginate(15);

        return view('purchase-orders.index', compact('purchaseOrders'));
    }

    public function create()
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        return view('purchase-orders.create', compact('clients'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'budgeted_amount' => 'required|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $purchaseOrder = PurchaseOrder::create($validated);

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order created successfully.');
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['client', 'project', 'timeEntries' => function ($q) {
            $q->orderBy('start_time', 'desc');
        }]);

        return view('purchase-orders.show', compact('purchaseOrder'));
    }

    public function edit(PurchaseOrder $purchaseOrder)
    {
        // Can only edit draft POs
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only draft purchase orders can be edited.');
        }

        $clients = Client::orderBy('name')->pluck('name', 'id');
        return view('purchase-orders.edit', compact('purchaseOrder', 'clients'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Can only edit draft POs
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', 'Only draft purchase orders can be edited.');
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'budgeted_amount' => 'required|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $purchaseOrder->update($validated);

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order updated successfully.');
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        // Can only delete draft POs
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return redirect()->route('purchase-orders.index')
                ->with('error', 'Only draft purchase orders can be deleted.');
        }

        $purchaseOrder->delete();

        return redirect()->route('purchase-orders.index')
            ->with('success', 'Purchase order deleted successfully.');
    }

    public function activate(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return back()->with('error', 'Only draft purchase orders can be activated.');
        }

        $purchaseOrder->activate();

        return back()->with('success', 'Purchase order activated.');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        if (in_array($purchaseOrder->status, [PurchaseOrder::STATUS_COMPLETED, PurchaseOrder::STATUS_CANCELLED])) {
            return back()->with('error', 'This purchase order cannot be cancelled.');
        }

        $purchaseOrder->cancel();

        return back()->with('success', 'Purchase order cancelled.');
    }

    public function allocateTime(Request $request, PurchaseOrder $purchaseOrder)
    {
        $validated = $request->validate([
            'time_entry_ids' => 'required|array',
            'time_entry_ids.*' => 'exists:time_entries,id',
        ]);

        // Only open POs can receive allocations
        if (!in_array($purchaseOrder->status, [PurchaseOrder::STATUS_OPEN, PurchaseOrder::STATUS_PARTIALLY_USED])) {
            return back()->with('error', 'Purchase order must be open to allocate time.');
        }

        $timeEntries = TimeEntry::whereIn('id', $validated['time_entry_ids'])->get();

        foreach ($timeEntries as $entry) {
            // Only link approved entries
            if ($entry->status === TimeEntry::STATUS_APPROVED) {
                $entry->update(['purchase_order_id' => $purchaseOrder->id]);
            }
        }

        $purchaseOrder->recalculateUsedAmount();

        return back()->with('success', 'Time entries allocated to purchase order.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index()
    {
        $purchaseOrders = PurchaseOrder::with(['client', 'project'])
            ->withCount('documents')
            ->latest()
            ->paginate(15);

        return view('purchase-orders.index', compact('purchaseOrders'));
    }

    public function create(Request $request)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $selectedClient = $request->client_id ? Client::find($request->client_id) : null;

        return view('purchase-orders.create', compact('clients', 'selectedClient'));
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
            $q->orderBy('entry_date', 'desc')->orderByDesc('id');
        }, 'documents']);

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
        if (! $purchaseOrder->canBeActivated()) {
            return back()->with('error', 'Only draft purchase orders can be activated.');
        }

        $purchaseOrder->activate();

        return back()->with('success', 'Purchase order activated.');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        if (! $purchaseOrder->canBeCancelled()) {
            return back()->with('error', 'This purchase order cannot be cancelled.');
        }

        $purchaseOrder->cancel();

        return back()->with('success', 'Purchase order cancelled.');
    }

    public function complete(PurchaseOrder $purchaseOrder)
    {
        if (! $purchaseOrder->canTransitionTo(PurchaseOrder::STATUS_COMPLETED)) {
            return back()->with('error', 'This purchase order cannot be marked as completed.');
        }

        $purchaseOrder->complete();

        return back()->with('success', 'Purchase order marked as completed.');
    }

    public function reopen(PurchaseOrder $purchaseOrder)
    {
        if (! $purchaseOrder->reopen()) {
            return back()->with('error', 'This purchase order cannot be reopened.');
        }

        return back()->with('success', 'Purchase order reopened.');
    }
}

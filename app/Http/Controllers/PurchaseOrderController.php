<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;

/**
 * Client-issued commercial documents — purchase orders with a fixed
 * budget and contracts whose budget is implied from rate × business
 * days × allocation × 8h — the budget envelope time entries and
 * invoices are attributed to. Plain CRUD plus model-guarded state
 * moves (activate, cancel, complete, reopen); only drafts are edited
 * or deleted, except that live contracts may instead be amended
 * (PurchaseOrder::amend records the term history). The document type
 * is chosen at creation and immutable after. Nothing here posts to
 * the IFRS ledger; invoicing against a PO happens in
 * InvoiceController, which keeps its used_amount in sync via the
 * observer chain.
 */
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

    /**
     * Validation rules for the given document type. The union keeps
     * the base rules for keys the type branch doesn't repeat — the
     * branch array must sit on the LEFT so its required dates win
     * over the nullable base.
     */
    private function rulesFor(string $type): array
    {
        $base = [
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ];

        if ($type === PurchaseOrder::TYPE_CONTRACT) {
            return [
                'rate' => 'required|numeric|min:0',
                'allocation' => 'required|numeric|min:0.01|max:100',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ] + $base;
        }

        return [
            'budgeted_amount' => 'required|numeric|min:0',
        ] + $base;
    }

    public function store(Request $request)
    {
        // `sometimes`: a payload with no type is a purchase order, the
        // column default — anything else must name one of the two kinds.
        $validated = $request->validate(
            ['type' => 'sometimes|in:purchase_order,contract']
            + $this->rulesFor($request->input('type', PurchaseOrder::TYPE_PURCHASE_ORDER))
        );

        // client_id is an unfillable FK — assign it explicitly.
        $purchaseOrder = new PurchaseOrder;
        $purchaseOrder->fill(collect($validated)->except(['client_id'])->all());
        $purchaseOrder->client_id = $validated['client_id'];
        $purchaseOrder->save();

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', __($purchaseOrder->isContract() ? 'purchase_orders.contract_created' : 'purchase_orders.po_created'));
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['client', 'project', 'timeEntries' => function ($q) {
            $q->orderBy('entry_date', 'desc')->orderByDesc('id');
        }, 'documents', 'amendments.user']);

        return view('purchase-orders.show', compact('purchaseOrder'));
    }

    public function edit(PurchaseOrder $purchaseOrder)
    {
        // Can only edit draft POs — live contracts are amended instead
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', __('purchase_orders.only_drafts_editable'));
        }

        $clients = Client::orderBy('name')->pluck('name', 'id');

        return view('purchase-orders.edit', compact('purchaseOrder', 'clients'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        // Can only edit draft POs — live contracts are amended instead
        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', __('purchase_orders.only_drafts_editable'));
        }

        // The type is immutable: rules branch on the stored type and
        // no `type` key is validated, so it can never be re-filled.
        $validated = $request->validate($this->rulesFor($purchaseOrder->type));

        // client_id is an unfillable FK — assign it explicitly.
        $purchaseOrder->fill(collect($validated)->except(['client_id'])->all());
        $purchaseOrder->client_id = $validated['client_id'];
        $purchaseOrder->save();

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', __($purchaseOrder->isContract() ? 'purchase_orders.contract_updated' : 'purchase_orders.po_updated'));
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

    public function amendForm(PurchaseOrder $purchaseOrder)
    {
        if (! $purchaseOrder->canBeAmended()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', __('purchase_orders.only_live_contracts_amendable'));
        }

        return view('purchase-orders.amend', compact('purchaseOrder'));
    }

    /**
     * Record an amendment against a live contract: the model snapshots
     * old → new terms and recomputes the implied budget atomically.
     */
    public function amendStore(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (! $purchaseOrder->canBeAmended()) {
            return redirect()->route('purchase-orders.show', $purchaseOrder)
                ->with('error', __('purchase_orders.only_live_contracts_amendable'));
        }

        $validated = $request->validate([
            'rate' => 'required|numeric|min:0',
            'allocation' => 'required|numeric|min:0.01|max:100',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]);

        $purchaseOrder->amend(
            collect($validated)->only(['rate', 'allocation', 'start_date', 'end_date'])->all(),
            $validated['reason'] ?? null,
            $request->user(),
        );

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', __('purchase_orders.amendment_recorded'));
    }
}

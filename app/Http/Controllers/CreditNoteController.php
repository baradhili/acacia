<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreditNoteController extends Controller
{
    public function index(Request $request)
    {
        $query = CreditNote::with(['client', 'invoice']);

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by client
        if ($request->has('client_id') && $request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        $creditNotes = $query->latest()->paginate(15);
        $clients = Client::orderBy('name')->pluck('name', 'id');

        return view('credit-notes.index', compact('creditNotes', 'clients'));
    }

    public function create(Request $request)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        
        $selectedClient = $request->client_id ? Client::with('invoices')->find($request->client_id) : null;
        $selectedInvoice = $request->invoice_id ? Invoice::with('items')->find($request->invoice_id) : null;

        return view('credit-notes.create', compact(
            'clients',
            'selectedClient',
            'selectedInvoice'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'issue_date' => 'required|date',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();
        try {
            // Calculate totals
            $total = 0;
            foreach ($validated['items'] as $item) {
                $subtotal = $item['quantity'] * $item['unit_price'];
                $tax = $subtotal * (($item['tax_rate'] ?? config('australian.gst.rate', 10)) / 100);
                $total += $subtotal + $tax;
            }

            $creditNote = CreditNote::create([
                'client_id' => $validated['client_id'],
                'invoice_id' => $validated['invoice_id'] ?? null,
                'created_by' => Auth::id(),
                'issue_date' => $validated['issue_date'],
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'total' => $total,
                'remaining_amount' => $total,
            ]);

            // Create items
            foreach ($validated['items'] as $item) {
                $creditNote->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? config('australian.gst.rate', 10),
                ]);
            }

            DB::commit();

            return redirect()->route('credit-notes.show', $creditNote)
                ->with('success', 'Credit note created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error creating credit note: ' . $e->getMessage());
        }
    }

    public function show(CreditNote $creditNote)
    {
        $creditNote->load(['client', 'invoice', 'creator', 'items']);

        return view('credit-notes.show', compact('creditNote'));
    }

    public function edit(CreditNote $creditNote)
    {
        if ($creditNote->status !== CreditNote::STATUS_ISSUED) {
            return redirect()->route('credit-notes.show', $creditNote)
                ->with('error', 'Only issued credit notes can be edited.');
        }

        $clients = Client::orderBy('name')->pluck('name', 'id');

        return view('credit-notes.edit', compact('creditNote', 'clients'));
    }

    public function update(Request $request, CreditNote $creditNote)
    {
        if ($creditNote->status !== CreditNote::STATUS_ISSUED) {
            return redirect()->route('credit-notes.show', $creditNote)
                ->with('error', 'Only issued credit notes can be edited.');
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'issue_date' => 'required|date',
            'reason' => 'required|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();
        try {
            // Calculate totals
            $total = 0;
            foreach ($validated['items'] as $item) {
                $subtotal = $item['quantity'] * $item['unit_price'];
                $tax = $subtotal * (($item['tax_rate'] ?? config('australian.gst.rate', 10)) / 100);
                $total += $subtotal + $tax;
            }

            $creditNote->update([
                'client_id' => $validated['client_id'],
                'issue_date' => $validated['issue_date'],
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? null,
                'total' => $total,
                'remaining_amount' => $total,
            ]);

            // Delete and recreate items
            $creditNote->items()->delete();

            foreach ($validated['items'] as $item) {
                $creditNote->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? config('australian.gst.rate', 10),
                ]);
            }

            DB::commit();

            return redirect()->route('credit-notes.show', $creditNote)
                ->with('success', 'Credit note updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error updating credit note: ' . $e->getMessage());
        }
    }

    public function destroy(CreditNote $creditNote)
    {
        if ($creditNote->status !== CreditNote::STATUS_ISSUED) {
            return redirect()->route('credit-notes.index')
                ->with('error', 'Only issued credit notes can be deleted.');
        }

        $creditNote->delete();

        return redirect()->route('credit-notes.index')
            ->with('success', 'Credit note deleted successfully.');
    }

    /**
     * Create credit note from invoice (full refund)
     */
    public function createFromInvoice(Invoice $invoice)
    {
        if ($invoice->status === Invoice::STATUS_PAID) {
            return back()->with('error', 'Cannot create credit note for fully paid invoice.');
        }

        $creditNoteItems = [];
        foreach ($invoice->items as $item) {
            $creditNoteItems[] = [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
            ];
        }

        return view('credit-notes.create-from-invoice', compact(
            'invoice',
            'creditNoteItems'
        ));
    }

    /**
     * Create credit note for specific items from invoice (partial refund)
     */
    public function createPartialFromInvoice(Request $request, Invoice $invoice)
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'exists:invoice_items,id',
            'reason' => 'required|string',
        ]);

        $items = $invoice->items()->whereIn('id', $request->item_ids)->get();
        
        $creditNoteItems = [];
        foreach ($items as $item) {
            $creditNoteItems[] = [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
            ];
        }

        return view('credit-notes.create-partial-from-invoice', compact(
            'invoice',
            'creditNoteItems',
            'request'
        ));
    }

    /**
     * Apply credit note to invoice
     */
    public function applyToInvoice(Request $request, CreditNote $creditNote)
    {
        $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        $invoice = Invoice::find($request->invoice_id);

        // Verify client matches
        if ($invoice->client_id !== $creditNote->client_id) {
            return back()->with('error', 'Invoice does not belong to this client.');
        }

        // Verify credit note has balance
        if (!$creditNote->hasRemainingBalance()) {
            return back()->with('error', 'Credit note has no remaining balance.');
        }

        if ($creditNote->applyToInvoice($invoice)) {
            return redirect()->route('credit-notes.show', $creditNote)
                ->with('success', 'Credit note applied to invoice successfully.');
        }

        return back()->with('error', 'Could not apply credit note.');
    }

    /**
     * Void credit note
     */
    public function void(CreditNote $creditNote)
    {
        if (!$creditNote->void()) {
            return back()->with('error', 'Cannot void this credit note.');
        }

        return redirect()->route('credit-notes.show', $creditNote)
            ->with('success', 'Credit note voided successfully.');
    }
}

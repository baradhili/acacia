<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['client', 'project']);

        // Filter by status
        if ($request->has('status') && $request->status) {
            if ($request->status === 'overdue') {
                $query->overdue();
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by client
        if ($request->has('client_id') && $request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        $invoices = $query->latest()->paginate(15);
        $clients = Client::orderBy('name')->pluck('name', 'id');

        return view('invoices.index', compact('invoices', 'clients'));
    }

    public function create(Request $request)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $projects = Project::with('client')->get()->groupBy('client_id');
        
        // Pre-select client/project if coming from PO or time entries
        $selectedClient = $request->client_id ? Client::find($request->client_id) : null;
        $selectedProject = $request->project_id ? Project::find($request->project_id) : null;
        $selectedPO = $request->purchase_order_id ? PurchaseOrder::find($request->purchase_order_id) : null;
        
        // Get unbilled time entries for selected client/project
        $timeEntries = collect();
        if ($selectedClient) {
            $timeEntriesQuery = TimeEntry::where('billable', true)
                ->where('status', TimeEntry::STATUS_APPROVED)
                ->whereDoesntHave('invoiceItem');
            
            if ($selectedProject) {
                $timeEntriesQuery->where('project_id', $selectedProject->id);
            } else {
                $timeEntriesQuery->whereHas('project', function ($q) use ($selectedClient) {
                    $q->where('client_id', $selectedClient->id);
                });
            }
            
            $timeEntries = $timeEntriesQuery->get();
        }

        return view('invoices.create', compact(
            'clients',
            'projects',
            'selectedClient',
            'selectedProject',
            'selectedPO',
            'timeEntries'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'time_entry_ids' => 'nullable|array',
            'time_entry_ids.*' => 'exists:time_entries,id',
        ]);

        DB::beginTransaction();
        try {
            $invoice = Invoice::create([
                'client_id' => $validated['client_id'],
                'project_id' => $validated['project_id'] ?? null,
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'created_by' => Auth::id(),
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? config('australian.invoice_terms'),
            ]);

            // Create invoice items
            foreach ($validated['items'] as $index => $item) {
                $invoiceItem = $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? config('australian.gst.rate', 10),
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'sort_order' => $index,
                ]);
            }

            // Mark time entries as invoiced if provided
            if (!empty($validated['time_entry_ids'])) {
                TimeEntry::whereIn('id', $validated['time_entry_ids'])
                    ->update(['invoiced' => true]);
            }

            $invoice->recalculateTotals();

            DB::commit();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Invoice created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error creating invoice: ' . $e->getMessage());
        }
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['client', 'project', 'creator', 'items', 'payments', 'allocations', 'documents']);
        
        return view('invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice)
    {
        if (!$invoice->canBeEdited()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited.');
        }

        $clients = Client::orderBy('name')->pluck('name', 'id');
        $projects = Project::with('client')->get()->groupBy('client_id');

        return view('invoices.edit', compact('invoice', 'clients', 'projects'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if (!$invoice->canBeEdited()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited.');
        }

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();
        try {
            $invoice->update([
                'client_id' => $validated['client_id'],
                'project_id' => $validated['project_id'] ?? null,
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
            ]);

            // Delete existing items and recreate
            $invoice->items()->delete();

            foreach ($validated['items'] as $index => $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? config('australian.gst.rate', 10),
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'sort_order' => $index,
                ]);
            }

            $invoice->recalculateTotals();

            DB::commit();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Invoice updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error updating invoice: ' . $e->getMessage());
        }
    }

    public function destroy(Invoice $invoice)
    {
        if (!$invoice->canBeEdited()) {
            return redirect()->route('invoices.index')
                ->with('error', 'Only draft invoices can be deleted.');
        }

        // Unmark time entries
        foreach ($invoice->items as $item) {
            if ($item->time_entry_id) {
                TimeEntry::where('id', $item->time_entry_id)->update(['invoiced' => false]);
            }
        }

        $invoice->delete();

        return redirect()->route('invoices.index')
            ->with('success', 'Invoice deleted successfully.');
    }

    public function send(Invoice $invoice)
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return back()->with('error', 'Only draft invoices can be sent.');
        }

        $invoice->markAsSent();

        // Send email notification with PDF attachment
        if ($invoice->client->email) {
            try {
                Mail::to($invoice->client->email)->send(new InvoiceMail($invoice));
            } catch (\Exception $e) {
                // Log error but don't fail the request
                \Log::error('Failed to send invoice email', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return back()->with('success', 'Invoice marked as sent.');
    }

    public function markViewed(Invoice $invoice)
    {
        $invoice->markAsViewed();
        return back()->with('success', 'Invoice marked as viewed.');
    }

    public function cancel(Invoice $invoice)
    {
        if (!$invoice->canBeCancelled()) {
            return back()->with('error', 'This invoice cannot be cancelled.');
        }

        $invoice->cancel();

        return back()->with('success', 'Invoice cancelled.');
    }

    /**
     * Void an invoice (alias for cancel)
     */
    public function void(Invoice $invoice)
    {
        if (!$invoice->canBeCancelled()) {
            return back()->with('error', 'This invoice cannot be voided.');
        }

        $invoice->cancel();

        return back()->with('success', 'Invoice voided.');
    }

    /**
     * Duplicate an invoice
     */
    public function duplicate(Invoice $invoice)
    {
        $newInvoice = Invoice::create([
            'client_id' => $invoice->client_id,
            'project_id' => $invoice->project_id,
            'created_by' => Auth::id(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'status' => Invoice::STATUS_DRAFT,
        ]);

        foreach ($invoice->items as $item) {
            $newInvoice->items()->create([
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
                'discount_percent' => $item->discount_percent,
                'sort_order' => $item->sort_order,
            ]);
        }

        $newInvoice->recalculateTotals();

        return redirect()->route('invoices.show', $newInvoice)
            ->with('success', 'Invoice duplicated successfully.');
    }

    /**
     * Add late fee to an overdue invoice.
     */
    public function addLateFee(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'late_fee_amount' => 'required|numeric|min:0.01',
        ]);

        $invoice->items()->create([
            'description' => 'Late Fee',
            'quantity' => 1,
            'unit_price' => $validated['late_fee_amount'],
            'tax_rate' => 0,
        ]);

        $invoice->recalculateTotals();

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Late fee applied successfully.');
    }

    /**
     * Bulk send invoices
     */
    public function bulkSend(Request $request)
    {
        $invoiceIds = $request->input('invoice_ids', []);
        if (empty($invoiceIds)) {
            // If no IDs provided, send all draft invoices
            Invoice::where('status', Invoice::STATUS_DRAFT)->get()->each(function ($invoice) {
                if ($invoice->canTransitionTo(Invoice::STATUS_SENT)) {
                    $invoice->markAsSent();
                }
            });
        } else {
            Invoice::whereIn('id', $invoiceIds)->get()->each(function ($invoice) {
                if ($invoice->canTransitionTo(Invoice::STATUS_SENT)) {
                    $invoice->markAsSent();
                }
            });
        }

        return back()->with('success', 'Invoices sent successfully.');
    }

    /**
     * Generate invoice from selected time entries
     */
    public function createFromTimeEntries(Request $request)
    {
        $request->validate([
            'time_entry_ids' => 'required|array|min:1',
            'time_entry_ids.*' => 'exists:time_entries,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $timeEntries = TimeEntry::with('project')->find($request->time_entry_ids);
        
        // Determine client
        $clientId = $request->client_id;
        if (!$clientId && $timeEntries->isNotEmpty()) {
            $firstProject = $timeEntries->first()->project;
            $clientId = $firstProject->client_id ?? null;
        }

        if (!$clientId) {
            return back()->with('error', 'Could not determine client.');
        }

        $client = Client::find($clientId);

        // Calculate totals
        $subtotal = $timeEntries->sum(function ($entry) {
            return $entry->hours * $entry->effective_rate;
        });
        $taxAmount = $subtotal * (config('australian.gst.rate', 10) / 100);
        $total = $subtotal + $taxAmount;

        return view('invoices.create-from-time-entries', compact(
            'timeEntries',
            'client',
            'subtotal',
            'taxAmount',
            'total'
        ));
    }

    /**
     * Generate invoice from purchase order
     */
    public function createFromPurchaseOrder(PurchaseOrder $purchaseOrder)
    {
        $timeEntries = $purchaseOrder->timeEntries()
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->whereNull('invoice_item_id') // Only uninvoiced
            ->get();

        if ($timeEntries->isEmpty()) {
            return back()->with('error', 'No uninvoiced time entries found for this purchase order.');
        }

        $client = $purchaseOrder->client;
        $project = $purchaseOrder->project;

        return view('invoices.create-from-po', compact(
            'purchaseOrder',
            'timeEntries',
            'client',
            'project'
        ));
    }

    /**
     * Record partial payment
     */
    public function recordPayment(Request $request, Invoice $invoice)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:' . $invoice->amount_due,
            'payment_date' => 'required|date',
            'payment_method' => 'required|string',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Create payment
            $payment = Payment::create([
                'client_id' => $invoice->client_id,
                'received_by' => Auth::id(),
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Allocate to this invoice
            $payment->allocateToInvoice($invoice, $validated['amount']);

            DB::commit();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Payment recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error recording payment: ' . $e->getMessage());
        }
    }

    /**
     * Generate PDF view
     */
    public function pdf(Invoice $invoice)
    {
        $invoice->load(['client', 'project', 'items']);
        
        return view('invoices.pdf', compact('invoice'));
    }

    /**
     * Download PDF
     */
    public function downloadPdf(Invoice $invoice)
    {
        $invoice->load(['client', 'project', 'items']);
        
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoices.pdf', compact('invoice'));
        $pdf->setPaper('a4', 'portrait');
        
        return $pdf->download('invoice-' . $invoice->invoice_number . '.pdf');
    }

    /**
     * Show the apply credit note form for an invoice.
     */
    public function applyCredit(Invoice $invoice)
    {
        $creditNotes = \App\Models\CreditNote::where('client_id', $invoice->client_id)
            ->where('remaining_amount', '>', 0)
            ->orderByDesc('id')
            ->pluck('credit_note_number', 'id');

        if ($creditNotes->isEmpty()) {
            $creditNotes = \App\Models\CreditNote::orderByDesc('id')->pluck('credit_note_number', 'id');
        }

        return view('invoices.apply-credit', compact('invoice', 'creditNotes'));
    }
}

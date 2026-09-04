<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\Client;
use App\Models\CompanyProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Rules\NotInClosedPeriod;
use App\Services\IfrsPosting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['client', 'project'])->withCount('documents');

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

        // Get unbilled time entries for selected client/project.
        // Entries carry a denormalised client_id (forced from the
        // project when one is set), so the client filter covers both
        // project-based and directly-targeted entries.
        $timeEntries = collect();
        if ($selectedClient) {
            $timeEntriesQuery = TimeEntry::where('billable', true)
                ->where('status', TimeEntry::STATUS_APPROVED)
                ->whereDoesntHave('invoiceItem')
                ->where('client_id', $selectedClient->id);

            if ($selectedProject) {
                $timeEntriesQuery->where('project_id', $selectedProject->id);
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
            'items' => 'required_without:time_entry_ids|array',
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
            // Screening runs under the transaction with row locks: the
            // consumption check is a recheck that no concurrent request
            // took an entry between submission and now, and every entry
            // must resolve to this invoice's client.
            $timeEntries = ! empty($validated['time_entry_ids'])
                ? $this->invoiceableTimeEntries($validated['time_entry_ids'], (int) $validated['client_id'], forUpdate: true)
                : collect();

            $invoice = Invoice::createWithUniqueNumber([
                'client_id' => $validated['client_id'],
                'project_id' => $validated['project_id'] ?? null,
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'created_by' => Auth::id(),
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? config('australian.invoice.terms'),
            ]);

            // Create invoice items
            $sortOrder = 0;
            foreach ($validated['items'] ?? [] as $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? config('australian.gst.rate', 10),
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'sort_order' => $sortOrder++,
                ]);
            }

            // Checked unbilled time entries become linked invoice lines
            foreach ($timeEntries as $timeEntry) {
                $entryItem = InvoiceItem::createFromTimeEntry($timeEntry);
                $invoice->items()->create(array_merge($entryItem->getAttributes(), [
                    'sort_order' => $sortOrder++,
                ]));
            }

            $invoice->recalculateTotals();

            DB::commit();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Invoice created successfully.');
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error creating invoice: '.$e->getMessage());
        }
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['client', 'project', 'creator', 'items', 'allocations.payment', 'documents']);

        return view('invoices.show', compact('invoice'));
    }

    public function edit(Invoice $invoice)
    {
        if (! $invoice->canBeEdited()) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Only draft invoices can be edited.');
        }

        $clients = Client::orderBy('name')->pluck('name', 'id');
        $projects = Project::with('client')->get()->groupBy('client_id');

        return view('invoices.edit', compact('invoice', 'clients', 'projects'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if (! $invoice->canBeEdited()) {
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
            'items.*.id' => 'nullable|integer',
            'items.*.time_entry_id' => 'nullable|exists:time_entries,id',
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

            // Upsert items: keep existing item ids stable (preserving
            // time_entry_id and any TimeEntry.invoice_item_id links) rather
            // than deleting and recreating, which severed those links.
            $submittedIds = collect($validated['items'])
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            // Load existing items for this invoice keyed by id.
            $existingItems = $invoice->items()->get()->keyBy('id');

            // Delete items the user removed from the form.
            if ($submittedIds) {
                $invoice->items()->whereNotIn('id', $submittedIds)->delete();
            } else {
                $invoice->items()->delete();
            }

            foreach ($validated['items'] as $index => $item) {
                $itemId = isset($item['id']) ? (int) $item['id'] : null;
                $payload = [
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? config('australian.gst.rate', 10),
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'sort_order' => $index,
                ];

                if ($itemId && $existingItems->has($itemId)) {
                    // Preserve time_entry_id unless explicitly changed.
                    if (array_key_exists('time_entry_id', $item)) {
                        $payload['time_entry_id'] = $item['time_entry_id'];
                    }
                    $existingItems->get($itemId)->update($payload);
                } else {
                    // New line: link time_entry_id only if provided.
                    $payload['time_entry_id'] = $item['time_entry_id'] ?? null;
                    $invoice->items()->create($payload);
                }
            }

            $invoice->recalculateTotals();

            DB::commit();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Invoice updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error updating invoice: '.$e->getMessage());
        }
    }

    public function destroy(Invoice $invoice)
    {
        if (! $invoice->canBeEdited()) {
            return redirect()->route('invoices.index')
                ->with('error', 'Only draft invoices can be deleted.');
        }

        // Deleting the invoice cascades its items, which releases any
        // linked time entries (their invoiced state derives from
        // invoice_items.time_entry_id via TimeEntry::invoiceItem()).
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

    /**
     * Un-send: return a sent/overdue invoice to draft. Rejected when any
     * payment has been allocated — a payable invoice can never be a draft.
     */
    public function unsend(Invoice $invoice)
    {
        if ($invoice->revertToDraft()) {
            return back()->with('success', 'Invoice returned to draft.');
        }

        return back()->with('error',
            'Only sent invoices with no recorded payments can be returned to draft.');
    }

    public function cancel(Invoice $invoice)
    {
        if (! $invoice->canBeCancelled()) {
            return back()->with('error', 'This invoice cannot be cancelled.');
        }

        $invoice->cancel();

        return back()->with('success', 'Invoice cancelled.');
    }

    /**
     * Selection screen: pick a client's uninvoiced entries (or browse
     * all clients') and build an invoice from them.
     */
    public function createFromTimeEntries(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $clients = Client::orderBy('name')->pluck('name', 'id');
        $selectedClient = ! empty($validated['client_id'])
            ? Client::find($validated['client_id'])
            : null;

        $timeEntries = TimeEntry::with(['project', 'client', 'user'])
            ->where('billable', true)
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->whereDoesntHave('invoiceItem')
            ->when($selectedClient, fn ($query) => $query->where('client_id', $selectedClient->id))
            ->orderBy('entry_date')
            ->get();

        return view('invoices.create-from-time-entries', compact(
            'clients',
            'selectedClient',
            'timeEntries'
        ));
    }

    /**
     * Create an invoice from the selected unbilled time entries.
     */
    public function storeFromTimeEntries(Request $request)
    {
        $validated = $request->validate([
            'time_entry_ids' => 'required|array|min:1',
            'time_entry_ids.*' => 'exists:time_entries,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Screening runs under the transaction with row locks — see
            // store(). ValidationException is rethrown after rollback so
            // the field errors still reach the form.
            $timeEntries = $this->invoiceableTimeEntries($validated['time_entry_ids'], forUpdate: true);

            // Every entry must resolve to a single client.
            $clientIds = $timeEntries
                ->map(fn ($entry) => $entry->client_id ?? $entry->project?->client_id)
                ->filter()
                ->unique();
            if ($clientIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'time_entry_ids' => 'Selected time entries are not linked to any client.',
                ]);
            }
            if ($clientIds->count() > 1) {
                $names = Client::whereIn('id', $clientIds)->pluck('name')->implode(', ');

                throw ValidationException::withMessages([
                    'time_entry_ids' => "Selected time entries span multiple clients ({$names}). Invoice each client separately.",
                ]);
            }

            // Project/PO carry onto the invoice only when every entry
            // shares the same one; mixed selections stay unattributed.
            $projectIds = $timeEntries->pluck('project_id')->filter()->unique();
            $poIds = $timeEntries->pluck('purchase_order_id')->filter()->unique();

            $invoice = $this->createInvoiceFromEntries($timeEntries, [
                'client_id' => $clientIds->first(),
                'project_id' => $projectIds->count() === 1 ? $projectIds->first() : null,
                'purchase_order_id' => $poIds->count() === 1 ? $poIds->first() : null,
                'created_by' => Auth::id(),
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? config('australian.invoice.terms'),
            ]);

            DB::commit();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Invoice created successfully.');
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error creating invoice: '.$e->getMessage());
        }
    }

    /**
     * Generate invoice from purchase order
     */
    public function createFromPurchaseOrder(PurchaseOrder $purchaseOrder)
    {
        if (! $purchaseOrder->canBeInvoiced()) {
            return back()->with('error', 'Only open or partially used purchase orders can be invoiced.');
        }

        $timeEntries = $purchaseOrder->timeEntries()
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->where('billable', true)
            ->whereDoesntHave('invoiceItem')
            ->with(['project', 'client', 'user'])
            ->orderBy('entry_date')
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
     * Create an invoice from the PO's selected unbilled time entries.
     */
    public function storeFromPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (! $purchaseOrder->canBeInvoiced()) {
            return back()->with('error', 'Only open or partially used purchase orders can be invoiced.');
        }

        $validated = $request->validate([
            'time_entry_ids' => 'required|array|min:1',
            'time_entry_ids.*' => 'exists:time_entries,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Screening runs under the transaction with row locks — see
            // store(). ValidationException is rethrown after rollback so
            // the field errors still reach the form.
            $timeEntries = $this->invoiceableTimeEntries($validated['time_entry_ids'], forUpdate: true);

            $foreign = $timeEntries->reject(
                fn ($entry) => (int) $entry->purchase_order_id === (int) $purchaseOrder->id
            );
            if ($foreign->isNotEmpty()) {
                $ids = $foreign->pluck('id')->implode(', ');

                throw ValidationException::withMessages([
                    'time_entry_ids' => "Time entries #{$ids} do not belong to this purchase order.",
                ]);
            }

            // purchase_order_id on the invoice drives the observer chain
            // that keeps the PO's used_amount in sync.
            $invoice = $this->createInvoiceFromEntries($timeEntries, [
                'client_id' => $purchaseOrder->client_id,
                'project_id' => $purchaseOrder->project_id,
                'purchase_order_id' => $purchaseOrder->id,
                'created_by' => Auth::id(),
                'issue_date' => $validated['issue_date'],
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? config('australian.invoice.terms'),
            ]);

            DB::commit();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Invoice created successfully.');
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error creating invoice: '.$e->getMessage());
        }
    }

    /**
     * Record partial payment
     */
    public function recordPayment(Request $request, Invoice $invoice)
    {
        // Only outstanding invoices (sent/partially_paid/overdue) can
        // receive a payment — not drafts or cancelled invoices.
        if (in_array($invoice->status, [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID])) {
            $reason = match ($invoice->status) {
                Invoice::STATUS_DRAFT => 'Draft invoices cannot receive payments. Mark the invoice as sent first.',
                Invoice::STATUS_PAID => 'This invoice is already fully paid.',
                default => 'Cancelled invoices cannot receive payments.',
            };

            return back()->with('error', $reason);
        }

        $amountDue = (float) $invoice->amount_due;
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:'.$amountDue,
            'payment_date' => ['required', 'date', new NotInClosedPeriod],
            'payment_method' => 'required|string',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Create payment
            $payment = Payment::createWithUniqueNumber([
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

            // Ledger posting is best-effort (logged, non-fatal), matching
            // the bill-payment flow; ifrs:post-payments backfills failures.
            $payment->postToIFRS();

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Payment recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Error recording payment: '.$e->getMessage());
        }
    }

    /**
     * Generate PDF view
     */
    public function pdf(Invoice $invoice)
    {
        $invoice->load(['client', 'project', 'items', 'purchaseOrder']);
        $companyProfile = CompanyProfile::forEntity(IfrsPosting::resolveEntity()?->id);

        return view('invoices.pdf', compact('invoice', 'companyProfile'));
    }

    /**
     * Download PDF
     */
    public function downloadPdf(Invoice $invoice)
    {
        $invoice->load(['client', 'project', 'items', 'purchaseOrder']);
        $companyProfile = CompanyProfile::forEntity(IfrsPosting::resolveEntity()?->id);

        $pdf = Pdf::loadView('invoices.pdf', compact('invoice', 'companyProfile'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download('invoice-'.$invoice->invoice_number.'.pdf');
    }

    /**
     * Persist an invoice whose lines are the given (pre-screened) time
     * entries, one item per entry, linked via time_entry_id. Caller
     * owns the surrounding transaction.
     */
    private function createInvoiceFromEntries(iterable $timeEntries, array $invoiceAttributes): Invoice
    {
        $invoice = Invoice::createWithUniqueNumber($invoiceAttributes);

        $sortOrder = 0;
        foreach ($timeEntries as $timeEntry) {
            $entryItem = InvoiceItem::createFromTimeEntry($timeEntry);
            $invoice->items()->create(array_merge($entryItem->getAttributes(), [
                'sort_order' => $sortOrder++,
            ]));
        }

        $invoice->recalculateTotals();

        return $invoice;
    }

    /**
     * Fetch time entries selected for invoicing, screening that every
     * one is approved, billable and not already on a live invoice — and,
     * when invoicing for a specific client, that the entry resolves to
     * that client. Throws ValidationException, so callers must either
     * invoke it before opening a try/catch that swallows exceptions or
     * rethrow the ValidationException from inside it.
     *
     * $forUpdate re-reads the rows under a row-level lock inside the
     * caller's transaction: a concurrent request that consumed an entry
     * after the unlocked screen is caught by the invoiceItem() recheck
     * here, before any invoice item is created.
     */
    private function invoiceableTimeEntries(array $ids, ?int $clientId = null, bool $forUpdate = false): Collection
    {
        $query = TimeEntry::with('project')->whereIn('id', $ids);
        $entries = $forUpdate ? $query->lockForUpdate()->get() : $query->get();

        if ($entries->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'time_entry_ids' => 'One or more selected time entries no longer exist.',
            ]);
        }

        $reasons = [];
        foreach ($entries as $entry) {
            if ($entry->status !== TimeEntry::STATUS_APPROVED) {
                $reasons[] = "#{$entry->id} is not approved";

                continue;
            }
            if (! $entry->billable) {
                $reasons[] = "#{$entry->id} is not billable";

                continue;
            }
            if ($entry->invoiceItem()->exists()) {
                $reasons[] = "#{$entry->id} is already invoiced";

                continue;
            }

            $resolvedClient = $entry->client_id ?? $entry->project?->client_id;
            if ($clientId !== null && (int) $resolvedClient !== $clientId) {
                $reasons[] = "#{$entry->id} belongs to another client";
            }
        }

        if ($reasons !== []) {
            throw ValidationException::withMessages([
                'time_entry_ids' => 'Cannot invoice: '.implode('; ', $reasons).'.',
            ]);
        }

        return $entries;
    }
}

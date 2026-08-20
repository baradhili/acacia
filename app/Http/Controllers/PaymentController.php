<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReceiptMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['client', 'receiver'])->withCount('documents');

        // Filter by client
        if ($request->has('client_id') && $request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->start_date) {
            $query->where('payment_date', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->where('payment_date', '<=', $request->end_date);
        }

        $payments = $query->latest()->paginate(15);
        $clients = Client::orderBy('name')->pluck('name', 'id');

        return view('payments.index', compact('payments', 'clients'));
    }

    public function create(Request $request)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $paymentMethods = Payment::paymentMethods();
        
        $selectedClient = $request->client_id ? Client::with('invoices')->find($request->client_id) : null;

        return view('payments.create', compact(
            'clients',
            'paymentMethods',
            'selectedClient'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'allocate_type' => 'required|in:manual,no',
            'invoice_allocations' => 'nullable|array',
            'invoice_allocations.*.invoice_id' => 'required_with:invoice_allocations|exists:invoices,id',
            'invoice_allocations.*.amount' => 'required_with:invoice_allocations|numeric|min:0',
        ]);

        // Unchecked rows submit nothing; drop anything incomplete defensively.
        $allocations = array_filter($validated['invoice_allocations'] ?? [], function ($allocation) {
            return !empty($allocation['invoice_id']) && (float) ($allocation['amount'] ?? 0) > 0;
        });

        if ($validated['allocate_type'] === 'manual' && empty($allocations)) {
            return back()->withInput()->with('error',
                'Select at least one invoice to allocate, or choose "Leave unallocated".');
        }

        // Validate every allocation against the invoice it targets BEFORE
        // anything is created — same rules as allocate(): the invoice must
        // belong to the paying client, must be allocatable (drafts have to
        // be sent first), and cannot be over-allocated.
        foreach ($allocations as $allocation) {
            $invoice = Invoice::find($allocation['invoice_id']);

            if ((int) $invoice->client_id !== (int) $validated['client_id']) {
                return back()->withInput()->with('error',
                    "Invoice {$invoice->invoice_number} does not belong to this client.");
            }

            $reason = match ($invoice->status) {
                Invoice::STATUS_DRAFT => 'it is still a draft — mark it as sent first',
                Invoice::STATUS_PAID => 'it is already fully paid',
                Invoice::STATUS_CANCELLED => 'it is cancelled',
                default => null,
            };
            if ($reason !== null) {
                return back()->withInput()->with('error',
                    "Invoice {$invoice->invoice_number} cannot be allocated: {$reason}.");
            }

            if ((float) $allocation['amount'] > $invoice->amount_due) {
                return back()->withInput()->with('error',
                    "Allocation for {$invoice->invoice_number} exceeds its outstanding balance of $"
                    . number_format($invoice->amount_due, 2) . '.');
            }
        }

        $totalAllocated = array_sum(array_map(fn ($a) => (float) $a['amount'], $allocations));
        if ($totalAllocated > (float) $validated['amount']) {
            return back()->withInput()->with('error',
                'Total allocations ($' . number_format($totalAllocated, 2)
                . ') exceed the payment amount ($' . number_format((float) $validated['amount'], 2) . ').');
        }

        DB::beginTransaction();
        try {
            $payment = Payment::createWithUniqueNumber([
                'client_id' => $validated['client_id'],
                'received_by' => Auth::id(),
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Allocate payment
            foreach ($allocations as $allocation) {
                $invoice = Invoice::find($allocation['invoice_id']);
                if ($invoice) {
                    $payment->allocateToInvoice($invoice, (float) $allocation['amount']);
                }
            }
            // 'no' = leave unallocated

            DB::commit();

            // Ledger posting is best-effort (logged, non-fatal), matching
            // the bill-payment flow; ifrs:post-payments backfills failures.
            $payment->postToIFRS();

            // Send receipt email if client has email
            if ($payment->client->email) {
                try {
                    Mail::to($payment->client->email)->send(new PaymentReceiptMail($payment));
                } catch (\Exception $e) {
                    \Log::error('Failed to send payment receipt email', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return redirect()->route('payments.show', $payment)
                ->with('success', 'Payment recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error recording payment: ' . $e->getMessage());
        }
    }

    public function show(Payment $payment)
    {
        $payment->load(['client', 'receiver', 'allocations.invoice', 'documents']);

        // Invoice options for the allocate modal: outstanding invoices the
        // payment can be allocated to, plus the client's balance-carrying
        // drafts listed as disabled "send first" options so they are
        // visible rather than silently missing.
        $allocatableInvoices = Invoice::where('client_id', $payment->client_id)
            ->outstanding()
            ->get()
            ->filter(fn ($invoice) => $invoice->amount_due > 0)
            ->sortBy('due_date')
            ->values();
        $draftInvoices = Invoice::where('client_id', $payment->client_id)
            ->where('status', Invoice::STATUS_DRAFT)
            ->where('total', '>', 0)
            ->orderBy('due_date')
            ->get();

        return view('payments.show', compact('payment', 'allocatableInvoices', 'draftInvoices'));
    }

    public function edit(Payment $payment)
    {
        $payment->load(['client', 'allocations.invoice']);
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $paymentMethods = Payment::paymentMethods();

        return view('payments.edit', compact('payment', 'clients', 'paymentMethods'));
    }

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|string',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        // #12: reject shrinking the amount below what's already allocated,
        // otherwise unallocated_amount goes negative (hidden by the accessor)
        // and downstream allocation logic breaks.
        $allocatedAmount = (float) $payment->allocated_amount;
        if ((float) $validated['amount'] < $allocatedAmount) {
            return back()->withInput()->with('error',
                'Amount cannot be less than $' . number_format($allocatedAmount, 2)
                . ' already allocated to invoices. Remove the allocations first.');
        }

        // #13: reject changing the client when allocations exist — those
        // allocations point at the old client's invoices and would be
        // orphaned. The allocate() action already enforces client-match, so
        // a client change must be preceded by removing allocations.
        if ((int) $validated['client_id'] !== (int) $payment->client_id
            && $payment->allocations()->exists()) {
            return back()->withInput()->with('error',
                'Cannot change the client on a payment with existing invoice allocations. Remove them first.');
        }

        DB::beginTransaction();
        try {
            $payment->update([
                'client_id' => $validated['client_id'],
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Recompute allocated invoice statuses against the new amount.
            foreach ($payment->allocations as $allocation) {
                $allocation->invoice->updateStatusFromPayments();
            }

            DB::commit();

            return redirect()->route('payments.show', $payment)
                ->with('success', 'Payment updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error updating payment: ' . $e->getMessage());
        }
    }

    public function destroy(Payment $payment)
    {
        DB::beginTransaction();
        try {
            // Capture invoices, delete allocations, THEN recompute status
            // so updateStatusFromPayments() sees the allocations as gone.
            $invoiceIds = $payment->allocations()->pluck('invoice_id');
            $payment->allocations()->delete();

            foreach ($invoiceIds as $invoiceId) {
                $invoice = Invoice::find($invoiceId);
                if ($invoice) {
                    $invoice->updateStatusFromPayments();
                }
            }

            $payment->delete();

            DB::commit();

            return redirect()->route('payments.index')
                ->with('success', 'Payment deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error deleting payment: ' . $e->getMessage());
        }
    }

    /**
     * Get a client's invoices for payment allocation (AJAX).
     *
     * Allocatable invoices (sent/partially_paid/overdue with an outstanding
     * balance) come first, ordered by due date. Balance-carrying drafts are
     * included too, flagged allocatable => false, so the payment form can
     * show them greyed-out with a "mark as sent first" hint instead of them
     * silently disappearing from the list.
     */
    public function getClientInvoices(Client $client)
    {
        $invoices = Invoice::where('client_id', $client->id)
            ->whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
                Invoice::STATUS_DRAFT,
            ])
            // Only invoices with an outstanding balance (total > allocated).
            // Same correlated-subquery pattern as Invoice::scopeOverdue.
            ->whereRaw(
                'COALESCE(invoices.total, 0) - COALESCE(('
                . 'SELECT SUM(amount) FROM payment_allocations'
                . ' WHERE payment_allocations.invoice_id = invoices.id'
                . '), 0) > 0'
            )
            ->orderByRaw("CASE WHEN invoices.status = '" . Invoice::STATUS_DRAFT . "' THEN 1 ELSE 0 END")
            ->orderBy('due_date')
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status,
                    'allocatable' => $invoice->status !== Invoice::STATUS_DRAFT,
                    'total' => $invoice->total,
                    // Outstanding balance — the 100%-allocation default.
                    'amount_due' => round((float) $invoice->amount_due, 2),
                    'due_date' => $invoice->due_date,
                ];
            });

        return response()->json($invoices);
    }

    /**
     * Allocate payment to specific invoice
     */
    public function allocate(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $invoice = Invoice::find($validated['invoice_id']);

        // Verify client matches
        if ($invoice->client_id !== $payment->client_id) {
            return back()->with('error', 'Invoice does not belong to this client.');
        }

        // Only sent/partially_paid/overdue invoices can receive allocations
        // — same rule as recordPayment(). Without this guard a crafted
        // request could allocate to a draft, and updateStatusFromPayments()
        // would flip it to partially_paid bypassing the state machine.
        $reason = match ($invoice->status) {
            Invoice::STATUS_DRAFT => 'it is still a draft — mark it as sent first',
            Invoice::STATUS_PAID => 'it is already fully paid',
            Invoice::STATUS_CANCELLED => 'it is cancelled',
            default => null,
        };
        if ($reason !== null) {
            return back()->with('error',
                "Invoice {$invoice->invoice_number} cannot be allocated: {$reason}.");
        }

        // The allocation cannot exceed the invoice's outstanding balance.
        if ($validated['amount'] > $invoice->amount_due) {
            return back()->with('error',
                'Amount exceeds the invoice\'s outstanding balance of $'
                . number_format($invoice->amount_due, 2) . '.');
        }

        // Verify amount available
        if ($validated['amount'] > $payment->unallocated_amount) {
            return back()->with('error', 'Amount exceeds unallocated payment balance.');
        }

        $payment->allocateToInvoice($invoice, $validated['amount']);

        return back()->with('success', 'Payment allocated successfully.');
    }

    /**
     * Remove allocation from invoice
     */
    public function removeAllocation(Payment $payment, Invoice $invoice)
    {
        if ($payment->removeAllocation($invoice)) {
            return back()->with('success', 'Allocation removed successfully.');
        }

        return back()->with('error', 'Could not remove allocation.');
    }

    /**
     * Remove ALL invoice allocations from a payment (bulk un-allocate) —
     * frees the full payment amount for re-allocation, or for a client
     * change on the edit page (blocked while allocations exist).
     */
    public function removeAllAllocations(Payment $payment)
    {
        DB::beginTransaction();
        try {
            // Capture invoices, delete allocations, THEN recompute status
            // so updateStatusFromPayments() sees the allocations as gone.
            $invoiceIds = $payment->allocations()->pluck('invoice_id');
            $payment->allocations()->delete();

            foreach ($invoiceIds as $invoiceId) {
                $invoice = Invoice::find($invoiceId);
                if ($invoice) {
                    $invoice->updateStatusFromPayments();
                }
            }

            DB::commit();

            return back()->with('success',
                'All allocations removed. The full payment amount is now unallocated.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error removing allocations: ' . $e->getMessage());
        }
    }
}

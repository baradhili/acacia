<?php

namespace App\Http\Controllers;

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
        $query = Payment::with(['client', 'receiver']);

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
            'allocate_type' => 'required|in:fifo,manual,no',
            'invoice_allocations' => 'required_if:allocate_type,manual|array',
            'invoice_allocations.*.invoice_id' => 'required_with:invoice_allocations|exists:invoices,id',
            'invoice_allocations.*.amount' => 'required_with:invoice_allocations|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $payment = Payment::create([
                'client_id' => $validated['client_id'],
                'received_by' => Auth::id(),
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Allocate payment
            if ($validated['allocate_type'] === 'fifo') {
                $payment->allocateToInvoicesFIFO();
            } elseif ($validated['allocate_type'] === 'manual' && !empty($validated['invoice_allocations'])) {
                foreach ($validated['invoice_allocations'] as $allocation) {
                    $invoice = Invoice::find($allocation['invoice_id']);
                    if ($invoice && $allocation['amount'] > 0) {
                        $payment->allocateToInvoice($invoice, $allocation['amount']);
                    }
                }
            }
            // 'no' = leave unallocated

            DB::commit();

            // Send receipt email if client has email
            // Mail::to($payment->client->email)->send(new PaymentReceiptMail($payment));

            return redirect()->route('payments.show', $payment)
                ->with('success', 'Payment recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error recording payment: ' . $e->getMessage());
        }
    }

    public function show(Payment $payment)
    {
        $payment->load(['client', 'receiver', 'allocations.invoice']);

        return view('payments.show', compact('payment'));
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

        DB::beginTransaction();
        try {
            // Update allocations status first
            $payment->updateAllocatedInvoicesStatus();

            $payment->update([
                'client_id' => $validated['client_id'],
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Recalculate allocations
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
            // Update invoice statuses
            $payment->updateAllocatedInvoicesStatus();

            // Delete allocations
            $payment->allocations()->delete();

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
     * Get outstanding invoices for a client (for AJAX)
     */
    public function getClientInvoices(Client $client)
    {
        $invoices = Invoice::where('client_id', $client->id)
            ->whereIn('status', [
                Invoice::STATUS_SENT,
                Invoice::STATUS_VIEWED,
                Invoice::STATUS_PARTIALLY_PAID,
                Invoice::STATUS_OVERDUE,
            ])
            ->where('amount_due', '>', 0)
            ->orderBy('due_date')
            ->get(['id', 'invoice_number', 'total', 'due_date']);

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
     * Re-allocate using FIFO
     */
    public function reallocateFifo(Payment $payment)
    {
        // Clear existing allocations
        $payment->allocations()->delete();
        
        // Reset invoice statuses
        $payment->updateAllocatedInvoicesStatus();

        // Re-allocate using FIFO
        $payment->allocateToInvoicesFIFO();

        return back()->with('success', 'Payment re-allocated using FIFO.');
    }
}

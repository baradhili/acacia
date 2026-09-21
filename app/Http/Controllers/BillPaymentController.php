<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use App\Rules\ActiveEmployee;
use App\Rules\NotInClosedPeriod;
use App\Services\BillLifecycleService;
use App\Services\EmployeeDirectory;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BillPaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = BillPayment::with(['supplier', 'payer', 'employee'])->withCount('documents');

        // Filter by supplier
        if ($request->has('supplier_id') && $request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter by status (pending = employee expenses awaiting approval)
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->start_date) {
            $query->where('payment_date', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->where('payment_date', '<=', $request->end_date);
        }

        $payments = $query->latest()->paginate(15);
        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');

        return view('bill-payments.index', compact('payments', 'suppliers'));
    }

    public function create(Request $request)
    {
        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');
        $paymentMethods = BillPayment::paymentMethods();
        $employees = EmployeeDirectory::active();

        $selectedSupplier = $request->supplier_id ? Supplier::find($request->supplier_id) : null;

        return view('bill-payments.create', compact('suppliers', 'paymentMethods', 'employees', 'selectedSupplier'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => ['required', 'date', new NotInClosedPeriod],
            'payment_method' => 'required|in:'.implode(',', array_keys(BillPayment::paymentMethods())),
            'employee_id' => ['nullable', 'required_if:payment_method,'.BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT, new ActiveEmployee],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'allocate_type' => 'required|in:manual,no',
            'bill_allocations' => 'nullable|array',
            'bill_allocations.*.bill_id' => 'required_with:bill_allocations|exists:bills,id',
            'bill_allocations.*.amount' => 'required_with:bill_allocations|numeric|min:0',
        ]);

        // Unchecked rows submit nothing; drop anything incomplete defensively.
        // (The form indexes pairs as bill_allocations[{bill_id}][...] — bare
        // [] names never pair bill_id and amount into one row in PHP.)
        $allocations = array_filter($validated['bill_allocations'] ?? [], function ($allocation) {
            return ! empty($allocation['bill_id']) && (float) ($allocation['amount'] ?? 0) > 0;
        });

        if ($validated['allocate_type'] === 'manual' && empty($allocations)) {
            return back()->withInput()->with('error',
                'Select at least one bill to allocate, or choose "Leave unallocated".');
        }

        DB::beginTransaction();
        try {
            // Employee-paid purchases sit pending until approved — nothing
            // reaches the ledger before sign-off. Every other method is
            // the company paying directly: completed, posts immediately.
            $employeePaid = $validated['payment_method'] === BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT;

            $payment = BillPayment::createWithUniqueNumber([
                'supplier_id' => $validated['supplier_id'],
                'paid_by' => Auth::id(),
                'employee_id' => $employeePaid ? $validated['employee_id'] : null,
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => $employeePaid ? BillPayment::STATUS_PENDING : BillPayment::STATUS_COMPLETED,
            ]);

            // Allocate payment
            foreach ($allocations as $allocation) {
                $bill = Bill::find($allocation['bill_id']);
                if ($bill) {
                    $payment->allocateToBill($bill, (float) $allocation['amount']);
                }
            }
            // 'no' = leave unallocated

            DB::commit();

            if ($employeePaid) {
                return redirect()->route('bill-payments.show', $payment)
                    ->with('success', 'Employee expense captured — awaiting approval before it posts to the ledger.');
            }

            // Ledger posting is best-effort (logged, non-fatal).
            $payment->postToIFRS();

            return redirect()->route('bill-payments.show', $payment)
                ->with('success', 'Supplier payment recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error recording payment: '.$e->getMessage());
        }
    }

    public function show(BillPayment $billPayment)
    {
        $billPayment->load(['supplier', 'payer', 'allocations.bill', 'documents']);

        return view('bill-payments.show', compact('billPayment'));
    }

    public function edit(BillPayment $billPayment)
    {
        if ($billPayment->status === BillPayment::STATUS_VOID) {
            return redirect()->route('bill-payments.show', $billPayment)
                ->with('error', 'Void payments cannot be edited.');
        }

        $billPayment->load(['supplier', 'allocations.bill', 'documents']);
        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');
        $paymentMethods = BillPayment::paymentMethods();
        $employees = EmployeeDirectory::active();

        return view('bill-payments.edit', compact('billPayment', 'suppliers', 'paymentMethods', 'employees'));
    }

    public function update(Request $request, BillPayment $billPayment)
    {
        if ($billPayment->status === BillPayment::STATUS_VOID) {
            return redirect()->route('bill-payments.show', $billPayment)
                ->with('error', 'Void payments cannot be edited.');
        }
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => ['required', 'date', new NotInClosedPeriod],
            'payment_method' => 'required|in:'.implode(',', array_keys(BillPayment::paymentMethods())),
            'employee_id' => ['nullable', 'required_if:payment_method,'.BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT, new ActiveEmployee],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Reject shrinking the amount below what's already allocated,
        // otherwise unallocated_amount goes negative (hidden by the accessor)
        // and downstream allocation logic breaks.
        $allocatedAmount = (float) $billPayment->allocated_amount;
        if ((float) $validated['amount'] < $allocatedAmount) {
            return back()->withInput()->with('error',
                'Amount cannot be less than $'.number_format($allocatedAmount, 2)
                .' already allocated to bills. Remove the allocations first.');
        }

        // Reject changing the supplier when allocations exist — those
        // allocations point at the old supplier's bills and would be
        // orphaned. The allocate() action already enforces supplier-match,
        // so a supplier change must be preceded by removing allocations.
        if ((int) $validated['supplier_id'] !== (int) $billPayment->supplier_id
            && $billPayment->allocations()->exists()) {
            return back()->withInput()->with('error',
                'Cannot change the supplier on a payment with existing bill allocations. Remove them first.');
        }

        // The method picks the ledger's credit leg (bank vs Employee
        // Reimbursements Payable), so it is locked once posted.
        $employeeId = (int) ($validated['employee_id'] ?? 0) ?: null;
        if ($billPayment->ifrs_payment_id
            && ($validated['payment_method'] !== $billPayment->payment_method
                || $employeeId !== ($billPayment->employee_id ?: null))) {
            return back()->withInput()->with('error',
                'Payment method and employee cannot change after the payment posts to the ledger. Void and re-enter instead.');
        }

        // Pending exists only in the employee-approval flow; another
        // method would strand the payment unpostable (nothing approves it).
        if ($billPayment->status === BillPayment::STATUS_PENDING
            && $validated['payment_method'] !== BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT) {
            return back()->withInput()->with('error',
                'This payment is pending approval as an employee expense. Void it and re-enter it as a company-paid payment instead.');
        }

        DB::beginTransaction();
        try {
            $billPayment->update([
                'supplier_id' => $validated['supplier_id'],
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'employee_id' => $validated['payment_method'] === BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT
                    ? ($validated['employee_id'] ?? null)
                    : null,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Recompute allocated bill statuses against the new amount.
            foreach ($billPayment->allocations as $allocation) {
                $allocation->bill->updateStatusFromPayments();
            }

            DB::commit();

            return redirect()->route('bill-payments.show', $billPayment)
                ->with('success', 'Payment updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error updating payment: '.$e->getMessage());
        }
    }

    public function destroy(BillPayment $billPayment)
    {
        // Posted payments must keep their audit trail: the journal entry
        // (and its hash-chained ledger rows) reference this payment.
        // Voiding reverses the ledger instead of orphaning it.
        if ($billPayment->ifrs_payment_id) {
            return back()->with('error',
                'This payment has been posted to the ledger and cannot be deleted. Void it instead — that reverses its ledger entry and any prepayment schedules.');
        }

        DB::beginTransaction();
        try {
            // Capture bills, delete allocations, THEN recompute status
            // so updateStatusFromPayments() sees the allocations as gone.
            $billIds = $billPayment->allocations()->pluck('bill_id');
            $billPayment->allocations()->delete();

            foreach ($billIds as $billId) {
                $bill = Bill::find($billId);
                if ($bill) {
                    $bill->updateStatusFromPayments();
                }
            }

            $billPayment->delete();

            DB::commit();

            return redirect()->route('bill-payments.index')
                ->with('success', 'Payment deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Error deleting payment: '.$e->getMessage());
        }
    }

    /**
     * Approve an employee-paid expense: the capture moves from pending to
     * completed and posts to the ledger (Cr Employee Reimbursements
     * Payable), and its allocated bills finally count it as paid.
     */
    public function approve(BillPayment $billPayment)
    {
        if ($billPayment->status !== BillPayment::STATUS_PENDING) {
            return back()->with('error', 'Only payments pending approval can be approved.');
        }

        if ($billPayment->payment_method !== BillPayment::METHOD_EMPLOYEE_REIMBURSEMENT) {
            return back()->with('error', 'Only employee-paid expenses go through approval.');
        }

        // Approving posts at the payment's original date — a closed
        // financial year cannot take the entry.
        $lockService = app(PeriodLockService::class);
        $paymentDate = Carbon::parse($billPayment->payment_date);
        if ($lockService->isDateBlocked($paymentDate)) {
            return back()->with('error', $lockService->dateBlockedMessage($paymentDate)
                .' The expense cannot be approved while its year is closed.');
        }

        DB::beginTransaction();
        try {
            $billPayment->update(['status' => BillPayment::STATUS_COMPLETED]);

            // The allocations now count as paid — bring the bills' statuses along.
            foreach ($billPayment->allocations as $allocation) {
                $allocation->bill->updateStatusFromPayments();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Error approving payment: '.$e->getMessage());
        }

        // Best-effort like every other posting path: on failure the
        // payment stays completed-but-unposted and the PostPaymentsToIfrs
        // backfill retries it.
        if ($billPayment->postToIFRS() === null) {
            return back()->with('error', 'Approved, but the ledger posting failed: '
                .$billPayment->lastPostingError.'. The scheduled backfill will retry.');
        }

        return back()->with('success', "Employee expense {$billPayment->payment_number} approved and posted — the employee can now be reimbursed.");
    }

    /**
     * Void a payment: remove all its allocations, restore the bills'
     * statuses, reverse its ledger entry (mirrored journal) and
     * neutralise any prepayment schedules it funded. The row is kept
     * (status void) for audit.
     */
    public function void(BillPayment $billPayment)
    {
        if ($billPayment->status === BillPayment::STATUS_VOID) {
            return back()->with('error', 'This payment is already void.');
        }

        // Voiding reverses the ledger entry at its original date — a
        // closed financial year cannot take the reversal.
        $lockService = app(PeriodLockService::class);
        $paymentDate = Carbon::parse($billPayment->payment_date);
        if ($lockService->isDateBlocked($paymentDate)) {
            return back()->with('error', $lockService->dateBlockedMessage($paymentDate)
                .' The payment cannot be voided while its year is closed.');
        }

        try {
            if (! $billPayment->void()) {
                return back()->with('error', $billPayment->lastVoidError
                    ?? 'The payment could not be voided.');
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Error voiding payment: '.$e->getMessage());
        }

        return back()->with('success', "Payment {$billPayment->payment_number} voided and its ledger entry reversed.");
    }

    /**
     * Get outstanding bills for a supplier (for AJAX)
     */
    public function getSupplierBills(Supplier $supplier)
    {
        $bills = Bill::where('supplier_id', $supplier->id)
            ->whereIn('status', [
                Bill::STATUS_OPEN,
                Bill::STATUS_PARTIALLY_PAID,
                Bill::STATUS_OVERDUE,
            ])
            // Only bills with room for another allocation (total > ALL
            // allocations — committed semantics, so a pending-approval
            // employee capture reserves its share). Correlated-subquery
            // pattern as in Bill::scopeOverdue, but that one counts only
            // completed payments: pending captures must not mask an
            // overdue bill the way they must reserve a balance here.
            ->whereRaw(
                'COALESCE(bills.total, 0) - COALESCE(('
                .'SELECT SUM(amount) FROM bill_payment_allocations'
                .' WHERE bill_payment_allocations.bill_id = bills.id'
                .'), 0) > 0'
            )
            ->get()
            ->map(function ($bill) {
                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'total' => $bill->total,
                    // Outstanding balance — the 100%-allocation default.
                    'amount_due' => round((float) $bill->amount_due, 2),
                    'due_date' => $bill->due_date,
                ];
            });

        return response()->json($bills);
    }

    /**
     * Allocate payment to specific bill
     */
    public function allocate(Request $request, BillPayment $billPayment)
    {
        $validated = $request->validate([
            'bill_id' => 'required|exists:bills,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $bill = Bill::find($validated['bill_id']);

        // Verify supplier matches
        if ($bill->supplier_id !== $billPayment->supplier_id) {
            return back()->with('error', 'Bill does not belong to this supplier.');
        }

        // Verify amount available
        if ($validated['amount'] > $billPayment->unallocated_amount) {
            return back()->with('error', 'Amount exceeds unallocated payment balance.');
        }

        $billPayment->allocateToBill($bill, (float) $validated['amount']);

        return back()->with('success', 'Payment allocated successfully.');
    }

    /**
     * Remove this payment's allocation to a bill. Reverses the bill's
     * ledger share (mirrored journal entry) and voids the payment when
     * it has no allocations left — same semantics as the bill-side
     * Unapply action, so the ledger never diverges from the allocations
     * regardless of which screen the removal happens from.
     */
    public function removeAllocation(BillPayment $billPayment, Bill $bill)
    {
        if ($billPayment->status === BillPayment::STATUS_VOID) {
            return back()->with('error', 'This payment is void.');
        }

        if (! $billPayment->allocations()->where('bill_id', $bill->id)->exists()) {
            return back()->with('error', 'Could not remove allocation.');
        }

        // Unapplying reverses the bill's ledger share at the payment's
        // original date — a closed financial year cannot take it.
        $lockService = app(PeriodLockService::class);
        $paymentDate = Carbon::parse($billPayment->payment_date);
        if ($lockService->isDateBlocked($paymentDate)) {
            return back()->with('error', $lockService->dateBlockedMessage($paymentDate)
                .' The allocation cannot be removed while the year is closed.');
        }

        try {
            BillLifecycleService::unapplyPayment($bill, $billPayment);
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not remove allocation: '.$e->getMessage());
        }

        return back()->with('success',
            "Allocation removed and the bill's share of the ledger entry reversed.");
    }
}

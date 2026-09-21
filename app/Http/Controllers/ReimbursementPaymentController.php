<?php

namespace App\Http\Controllers;

use App\Models\ReimbursementPayment;
use App\Rules\ActiveEmployee;
use App\Rules\NotInClosedPeriod;
use App\Services\EmployeeDirectory;
use App\Services\PeriodLockService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReimbursementPaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = ReimbursementPayment::with(['employee', 'payer'])->latest();

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $payments = $query->paginate(15);
        $employees = EmployeeDirectory::active();

        // Who is currently owed what — the queue worth acting on.
        $outstanding = $employees
            ->mapWithKeys(fn ($name, $id) => [$id => ReimbursementPayment::outstandingFor((int) $id)])
            ->filter(fn ($amount) => $amount > 0);

        return view('reimbursement-payments.index', compact('payments', 'employees', 'outstanding'));
    }

    public function create()
    {
        $employees = EmployeeDirectory::active();
        $paymentMethods = ReimbursementPayment::paymentMethods();

        // Employee id => amount the company still owes them; the form
        // pre-fills the payment amount when an employee is picked.
        $outstanding = $employees
            ->mapWithKeys(fn ($name, $id) => [$id => ReimbursementPayment::outstandingFor((int) $id)]);

        return view('reimbursement-payments.create', compact('employees', 'paymentMethods', 'outstanding'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => ['required', new ActiveEmployee],
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => ['required', 'date', new NotInClosedPeriod],
            'payment_method' => 'required|in:'.implode(',', array_keys(ReimbursementPayment::paymentMethods())),
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Never reimburse more than the approved-but-unpaid captures: the
        // Employee Reimbursements Payable balance would go negative. The
        // check and the insert run in one transaction under a lock on the
        // employee row, so two concurrent submissions for the same
        // employee serialize instead of both passing and over-paying.
        $payment = DB::transaction(function () use ($validated) {
            DB::table('employees')->where('id', $validated['employee_id'])->lockForUpdate()->first();

            $outstanding = ReimbursementPayment::outstandingFor((int) $validated['employee_id']);
            if ((float) $validated['amount'] > round($outstanding, 2) + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount exceeds what the company owes the employee'
                        .' ($'.number_format($outstanding, 2).').'
                        .' Check for pending expenses — only approved ones count.',
                ]);
            }

            return ReimbursementPayment::createWithUniqueNumber([
                'employee_id' => $validated['employee_id'],
                'paid_by' => Auth::id(),
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        // Ledger posting is best-effort (logged, non-fatal) — the
        // ifrs:post-payments backfill retries failures.
        if ($payment->postToIFRS() === null) {
            return redirect()->route('reimbursement-payments.show', $payment)
                ->with('error', 'Reimbursement recorded, but the ledger posting failed: '
                    .$payment->lastPostingError.'. The scheduled backfill will retry.');
        }

        return redirect()->route('reimbursement-payments.show', $payment)
            ->with('success', 'Reimbursement recorded and posted.');
    }

    public function show(ReimbursementPayment $reimbursementPayment)
    {
        $reimbursementPayment->load(['employee', 'payer', 'documents']);

        return view('reimbursement-payments.show', compact('reimbursementPayment'));
    }

    /**
     * Void a reimbursement: sets it void and posts a mirrored reversal of
     * its ledger entry. The employee's outstanding balance rises again.
     */
    public function void(ReimbursementPayment $reimbursementPayment)
    {
        if ($reimbursementPayment->status === ReimbursementPayment::STATUS_VOID) {
            return back()->with('error', 'This reimbursement is already void.');
        }

        $lockService = app(PeriodLockService::class);
        $paymentDate = Carbon::parse($reimbursementPayment->payment_date);
        if ($lockService->isDateBlocked($paymentDate)) {
            return back()->with('error', $lockService->dateBlockedMessage($paymentDate)
                .' The reimbursement cannot be voided while its year is closed.');
        }

        if (! $reimbursementPayment->void()) {
            return back()->with('error', 'The reimbursement could not be voided.');
        }

        return back()->with('success', "Reimbursement {$reimbursementPayment->payment_number} voided and its ledger entry reversed.");
    }
}

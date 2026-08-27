<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Project;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BillController extends Controller
{
    public function index(Request $request)
    {
        $query = Bill::with(['supplier', 'project'])->withCount('documents');

        // Filter by status
        if ($request->has('status') && $request->status) {
            if ($request->status === 'overdue') {
                $query->overdue();
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by supplier
        if ($request->has('supplier_id') && $request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $bills = $query->latest()->paginate(15);
        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');

        return view('bills.index', compact('bills', 'suppliers'));
    }

    public function create(Request $request)
    {
        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');
        $projects = Project::orderBy('name')->get();
        $purchaseAccounts = Bill::purchaseAccounts();
        $expenseAccounts = Bill::expenseAccounts();
        $paymentMethods = BillPayment::paymentMethods();

        $selectedSupplier = $request->supplier_id ? Supplier::find($request->supplier_id) : null;
        $selectedProject = $request->project_id ? Project::find($request->project_id) : null;

        return view('bills.create', compact(
            'suppliers',
            'projects',
            'purchaseAccounts',
            'expenseAccounts',
            'paymentMethods',
            'selectedSupplier',
            'selectedProject'
        ));
    }

    /**
     * Store a bill. When "paid_now" is set (parking, entertainment, online
     * purchases…), the bill, its payment, the full allocation and the IFRS
     * posting all happen here — the paid-at-entry mechanism.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'project_id' => 'nullable|exists:projects,id',
            'bill_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:bill_date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            // Per-line GST: 'gst' = the entered amount is GST-inclusive
            // (portion back-calculated); 'gst_add' = the entered amount is
            // ex-GST and GST is added on top; neither = GST-free.
            'items.*.gst' => 'nullable|boolean',
            'items.*.gst_add' => 'nullable|boolean',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.expense_account_id' => 'nullable|integer|exists:ifrs_accounts,id',
            // Prepaid service contracts: the payment debits the prepaid
            // asset account and amortises monthly over the service period.
            'items.*.is_prepaid' => 'nullable|boolean',
            'items.*.service_start' => 'required_with:items.*.is_prepaid|nullable|date',
            'items.*.service_end' => 'required_with:items.*.is_prepaid|nullable|date|after_or_equal:items.*.service_start',
            'items.*.amortise_to_account_id' => 'nullable|integer|exists:ifrs_accounts,id',
            'paid_now' => 'nullable|boolean',
            'payment_date' => 'required_if:paid_now,1|nullable|date',
            'payment_method' => 'required_if:paid_now,1|nullable|in:' . implode(',', array_keys(BillPayment::paymentMethods())),
            'payment_reference' => 'nullable|string|max:255',
            // Receipts uploaded alongside a bill paid at entry
            'documents' => 'nullable|array',
            'documents.*' => 'file|max:20480|mimes:pdf,jpg,jpeg,png,gif,doc,docx,xls,xlsx,txt,zip,rar',
        ]);

        DB::beginTransaction();
        try {
            $bill = Bill::createWithUniqueNumber([
                'supplier_id' => $validated['supplier_id'],
                'project_id' => $validated['project_id'] ?? null,
                'created_by' => Auth::id(),
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $index => $item) {
                $bill->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => (!empty($item['gst']) || !empty($item['gst_add'])) ? config('australian.gst.rate', 10) : 0,
                    // "Incl. GST" wins if both boxes are somehow submitted
                    'gst_added' => empty($item['gst']) && !empty($item['gst_add']),
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'expense_account_id' => $item['expense_account_id'] ?? null,
                    'is_prepaid' => !empty($item['is_prepaid']),
                    'service_start' => !empty($item['is_prepaid']) ? $item['service_start'] : null,
                    'service_end' => !empty($item['is_prepaid']) ? $item['service_end'] : null,
                    'amortise_to_account_id' => $item['amortise_to_account_id'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $bill->recalculateTotals();

            // Attach any receipt documents uploaded with the bill (paid-at-
            // entry expenses can never be edited afterwards, so the receipt
            // rides along with creation)
            foreach ($request->file('documents', []) as $file) {
                $bill->documents()->create([
                    'name' => $file->getClientOriginalName(),
                    'file_path' => $file->store('uploads/' . now()->format('Y/m'), 'public'),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => Auth::id(),
                ]);
            }

            // Paid-at-entry: create the payment, allocate the full total and
            // post to the ledger in the same transaction.
            if (!empty($validated['paid_now'])) {
                $payment = BillPayment::createWithUniqueNumber([
                    'supplier_id' => $bill->supplier_id,
                    'paid_by' => Auth::id(),
                    'amount' => $bill->total,
                    'payment_date' => $validated['payment_date'] ?? $validated['bill_date'],
                    'payment_method' => $validated['payment_method'],
                    'reference' => $validated['payment_reference'] ?? null,
                ]);

                $payment->allocateToBill($bill, (float) $bill->total);

                DB::commit();

                // Ledger posting is best-effort (logged, non-fatal), matching
                // the receipts flow.
                $payment->postToIFRS();

                return redirect()->route('bills.show', $bill)
                    ->with('success', 'Bill created and marked as paid.');
            }

            DB::commit();

            return redirect()->route('bills.show', $bill)
                ->with('success', 'Bill created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error creating bill: ' . $e->getMessage());
        }
    }

    public function show(Bill $bill)
    {
        $bill->load(['supplier', 'project', 'creator', 'items', 'allocations.billPayment', 'documents']);
        $paymentMethods = BillPayment::paymentMethods();

        return view('bills.show', compact('bill', 'paymentMethods'));
    }

    public function edit(Bill $bill)
    {
        if (!$bill->canBeEdited()) {
            return redirect()->route('bills.show', $bill)
                ->with('error', 'Only draft bills can be edited.');
        }

        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');
        $projects = Project::orderBy('name')->get();
        $purchaseAccounts = Bill::purchaseAccounts();
        $expenseAccounts = Bill::expenseAccounts();

        return view('bills.edit', compact('bill', 'suppliers', 'projects', 'purchaseAccounts', 'expenseAccounts'));
    }

    public function update(Request $request, Bill $bill)
    {
        if (!$bill->canBeEdited()) {
            return redirect()->route('bills.show', $bill)
                ->with('error', 'Only draft bills can be edited.');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'project_id' => 'nullable|exists:projects,id',
            'bill_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:bill_date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            // Same per-line GST treatment as store()
            'items.*.gst' => 'nullable|boolean',
            'items.*.gst_add' => 'nullable|boolean',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.expense_account_id' => 'nullable|integer|exists:ifrs_accounts,id',
            // Same prepaid service-contract fields as store()
            'items.*.is_prepaid' => 'nullable|boolean',
            'items.*.service_start' => 'required_with:items.*.is_prepaid|nullable|date',
            'items.*.service_end' => 'required_with:items.*.is_prepaid|nullable|date|after_or_equal:items.*.service_start',
            'items.*.amortise_to_account_id' => 'nullable|integer|exists:ifrs_accounts,id',
        ]);

        DB::beginTransaction();
        try {
            $bill->update([
                'supplier_id' => $validated['supplier_id'],
                'project_id' => $validated['project_id'] ?? null,
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            // Upsert items: keep existing item ids stable (preserving
            // expense_account_id links) rather than deleting and recreating.
            $submittedIds = collect($validated['items'])
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            // Load existing items for this bill keyed by id.
            $existingItems = $bill->items()->get()->keyBy('id');

            // Delete items the user removed from the form.
            if ($submittedIds) {
                $bill->items()->whereNotIn('id', $submittedIds)->delete();
            } else {
                $bill->items()->delete();
            }

            foreach ($validated['items'] as $index => $item) {
                $itemId = isset($item['id']) ? (int) $item['id'] : null;
                $payload = [
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => (!empty($item['gst']) || !empty($item['gst_add'])) ? config('australian.gst.rate', 10) : 0,
                    // "Incl. GST" wins if both boxes are somehow submitted
                    'gst_added' => empty($item['gst']) && !empty($item['gst_add']),
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'expense_account_id' => $item['expense_account_id'] ?? null,
                    'is_prepaid' => !empty($item['is_prepaid']),
                    'service_start' => !empty($item['is_prepaid']) ? $item['service_start'] : null,
                    'service_end' => !empty($item['is_prepaid']) ? $item['service_end'] : null,
                    'amortise_to_account_id' => $item['amortise_to_account_id'] ?? null,
                    'sort_order' => $index,
                ];

                if ($itemId && $existingItems->has($itemId)) {
                    $existingItems->get($itemId)->update($payload);
                } else {
                    $bill->items()->create($payload);
                }
            }

            $bill->recalculateTotals();

            DB::commit();

            return redirect()->route('bills.show', $bill)
                ->with('success', 'Bill updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error updating bill: ' . $e->getMessage());
        }
    }

    public function destroy(Bill $bill)
    {
        if (!$bill->canBeEdited()) {
            return redirect()->route('bills.index')
                ->with('error', 'Only draft bills can be deleted.');
        }

        $bill->delete();

        return redirect()->route('bills.index')
            ->with('success', 'Bill deleted successfully.');
    }

    /**
     * Mark a draft bill as open (received/confirmed, payable).
     */
    public function open(Bill $bill)
    {
        if ($bill->status !== Bill::STATUS_DRAFT) {
            return back()->with('error', 'Only draft bills can be marked open.');
        }

        $bill->markAsOpen();

        return back()->with('success', 'Bill marked as open.');
    }

    public function cancel(Bill $bill)
    {
        if (!$bill->canBeCancelled()) {
            return back()->with('error', 'This bill cannot be cancelled.');
        }

        $bill->cancel();

        return back()->with('success', 'Bill cancelled.');
    }

    /**
     * Record a payment against an outstanding bill.
     */
    public function recordPayment(Request $request, Bill $bill)
    {
        // Only outstanding bills (open/partially_paid/overdue) can receive a
        // payment — not drafts, cancelled or already-paid bills.
        if (in_array($bill->status, [Bill::STATUS_DRAFT, Bill::STATUS_CANCELLED, Bill::STATUS_PAID])) {
            return back()->with('error', 'Payments can only be recorded against outstanding bills.');
        }

        $amountDue = (float) $bill->amount_due;
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:' . $amountDue,
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:' . implode(',', array_keys(BillPayment::paymentMethods())),
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $payment = BillPayment::createWithUniqueNumber([
                'supplier_id' => $bill->supplier_id,
                'paid_by' => Auth::id(),
                'amount' => $validated['amount'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $payment->allocateToBill($bill, (float) $validated['amount']);

            DB::commit();

            // Ledger posting is best-effort (logged, non-fatal).
            $payment->postToIFRS();

            return redirect()->route('bills.show', $bill)
                ->with('success', 'Payment recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error recording payment: ' . $e->getMessage());
        }
    }
}

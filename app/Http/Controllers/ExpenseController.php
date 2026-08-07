<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Expense::with(['supplier', 'project', 'paidBy']);

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        // Filter by supplier
        if ($request->has('supplier_id') && $request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->start_date) {
            $query->where('expense_date', '>=', $request->start_date);
        }
        if ($request->has('end_date') && $request->end_date) {
            $query->where('expense_date', '<=', $request->end_date);
        }

        $expenses = $query->latest()->paginate(15);
        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');
        $categories = Expense::CATEGORIES;
        $statuses = [
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'paid' => 'Paid',
            'cancelled' => 'Cancelled',
        ];

        return view('expenses.index', compact(
            'expenses',
            'suppliers',
            'categories',
            'statuses'
        ));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');
        $categories = Expense::CATEGORIES;
        $paymentMethods = [
            'bank_transfer' => 'Bank Transfer',
            'credit_card' => 'Credit Card',
            'cash' => 'Cash',
            'cheque' => 'Cheque',
            'other' => 'Other',
        ];
        
        // Get projects for optional linking
        $projects = Project::where('status', Project::STATUS_ACTIVE)
            ->orderBy('name')
            ->pluck('name', 'id');
        
        // Pre-select project if provided via query string
        $selectedProject = $request->get('project_id');

        return view('expenses.create', compact(
            'suppliers',
            'categories',
            'paymentMethods',
            'projects',
            'selectedProject'
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'project_id' => 'nullable|exists:projects,id',
            'category' => 'required|string|in:' . implode(',', Expense::CATEGORIES),
            'amount' => 'required|numeric|min:0.01',
            'tax_amount' => 'nullable|numeric|min:0',
            'expense_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:expense_date',
            'description' => 'nullable|string|max:1000',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $total = $validated['amount'] + ($validated['tax_amount'] ?? 0);

        $expenseData = [
            'supplier_id' => $validated['supplier_id'],
            'project_id' => $validated['project_id'] ?? null,
            'category' => $validated['category'],
            'amount' => $validated['amount'],
            'tax_amount' => $validated['tax_amount'] ?? 0,
            'total' => $total,
            'expense_date' => $validated['expense_date'],
            'due_date' => $validated['due_date'] ?? null,
            'description' => $validated['description'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => Expense::STATUS_DRAFT,
        ];

        // Handle receipt upload
        if ($request->hasFile('receipt')) {
            $receipt = $request->file('receipt');
            $path = $receipt->store(
                'uploads/' . now()->format('Y/m'),
                'public'
            );
            $expenseData['receipt_path'] = $path;
        }

        $expense = Expense::create($expenseData);

        return redirect()
            ->route('expenses.show', $expense)
            ->with('success', 'Expense created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Expense $expense)
    {
        $expense->load(['supplier', 'project', 'paidBy', 'documents']);

        return view('expenses.show', compact('expense'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Expense $expense)
    {
        if (!$expense->canBeEdited()) {
            return redirect()
                ->route('expenses.show', $expense)
                ->with('error', 'This expense cannot be edited in its current status.');
        }

        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');
        $categories = Expense::CATEGORIES;
        $paymentMethods = [
            'bank_transfer' => 'Bank Transfer',
            'credit_card' => 'Credit Card',
            'cash' => 'Cash',
            'cheque' => 'Cheque',
            'other' => 'Other',
        ];
        
        // Get projects for optional linking
        $projects = Project::orderBy('name')->pluck('name', 'id');

        return view('expenses.edit', compact(
            'expense',
            'suppliers',
            'categories',
            'paymentMethods',
            'projects'
        ));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Expense $expense)
    {
        if (!$expense->canBeEdited()) {
            return redirect()
                ->route('expenses.show', $expense)
                ->with('error', 'This expense cannot be edited in its current status.');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'project_id' => 'nullable|exists:projects,id',
            'category' => 'required|string|in:' . implode(',', Expense::CATEGORIES),
            'amount' => 'required|numeric|min:0.01',
            'tax_amount' => 'nullable|numeric|min:0',
            'expense_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:expense_date',
            'description' => 'nullable|string|max:1000',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $total = $validated['amount'] + ($validated['tax_amount'] ?? 0);

        $expenseData = [
            'supplier_id' => $validated['supplier_id'],
            'project_id' => $validated['project_id'] ?? null,
            'category' => $validated['category'],
            'amount' => $validated['amount'],
            'tax_amount' => $validated['tax_amount'] ?? 0,
            'total' => $total,
            'expense_date' => $validated['expense_date'],
            'due_date' => $validated['due_date'] ?? null,
            'description' => $validated['description'] ?? null,
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];

        // Handle new receipt upload
        if ($request->hasFile('receipt')) {
            // Delete old receipt if exists
            if ($expense->receipt_path) {
                Storage::disk('public')->delete($expense->receipt_path);
            }
            $receipt = $request->file('receipt');
            $path = $receipt->store(
                'uploads/' . now()->format('Y/m'),
                'public'
            );
            $expenseData['receipt_path'] = $path;
        }

        $expense->update($expenseData);

        return redirect()
            ->route('expenses.show', $expense)
            ->with('success', 'Expense updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Expense $expense)
    {
        if (!$expense->canBeDeleted()) {
            return redirect()
                ->route('expenses.index')
                ->with('error', 'This expense cannot be deleted in its current status.');
        }

        // Delete receipt if exists
        if ($expense->receipt_path) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        $expense->delete();

        return redirect()
            ->route('expenses.index')
            ->with('success', 'Expense deleted successfully.');
    }

    /**
     * Submit expense for approval
     */
    public function submit(Expense $expense)
    {
        if (!$expense->submit()) {
            return redirect()
                ->route('expenses.show', $expense)
                ->with('error', 'This expense cannot be submitted.');
        }

        return redirect()
            ->route('expenses.show', $expense)
            ->with('success', 'Expense submitted for approval.');
    }

    /**
     * Approve expense
     */
    public function approve(Expense $expense)
    {
        if (!$expense->approve()) {
            return redirect()
                ->route('expenses.show', $expense)
                ->with('error', 'This expense cannot be approved.');
        }

        return redirect()
            ->route('expenses.show', $expense)
            ->with('success', 'Expense approved.');
    }

    /**
     * Pay expense
     */
    public function pay(Request $request, Expense $expense)
    {
        if (!$expense->canBePaid()) {
            return redirect()
                ->route('expenses.show', $expense)
                ->with('error', 'This expense cannot be paid.');
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:bank_transfer,credit_card,cash,cheque,other',
        ]);

        $expense->markAsPaid(
            $validated['payment_method'],
            Auth::id()
        );

        return redirect()
            ->route('expenses.show', $expense)
            ->with('success', 'Expense marked as paid.');
    }

    /**
     * Cancel expense
     */
    public function cancel(Expense $expense)
    {
        if (!$expense->cancel()) {
            return redirect()
                ->route('expenses.show', $expense)
                ->with('error', 'This expense cannot be cancelled.');
        }

        return redirect()
            ->route('expenses.show', $expense)
            ->with('success', 'Expense cancelled.');
    }

    /**
     * Download receipt
     */
    public function downloadReceipt(Expense $expense)
    {
        if (!$expense->receipt_path) {
            abort(404, 'Receipt not found.');
        }

        return Storage::disk('public')->download($expense->receipt_path);
    }
}

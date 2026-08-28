<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::withCount('documents')->paginate(15);
        return view('suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('suppliers.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validatedSupplier($request);

        Supplier::create($validated);

        return redirect()->route('suppliers.index')->with('success', 'Supplier created successfully.');
    }

    /**
     * Quick-add from the bill create/edit forms: creates the supplier and
     * returns it as JSON so the caller can drop it into its select.
     */
    public function quickStore(Request $request)
    {
        // The app only renders validation exceptions as JSON for api/*
        // paths (bootstrap/app.php), so validate manually to give the
        // fetch caller a 422 instead of a redirect.
        $validator = Validator::make($request->all(), $this->supplierRules());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $supplier = Supplier::create($validator->validated());

        return response()->json(['id' => $supplier->id, 'name' => $supplier->name]);
    }

    private function validatedSupplier(Request $request): array
    {
        return $request->validate($this->supplierRules());
    }

    private function supplierRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postcode' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'abn' => 'nullable|string|max:20',
            'category' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ];
    }

    public function show(Supplier $supplier)
    {
        $supplier->load('documents');
        return view('suppliers.show', compact('supplier'));
    }

    public function edit(Supplier $supplier)
    {
        $supplier->load('documents');
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $this->validatedSupplier($request);

        $supplier->update($validated);

        return redirect()->route('suppliers.index')->with('success', 'Supplier updated successfully.');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted successfully.');
    }
}

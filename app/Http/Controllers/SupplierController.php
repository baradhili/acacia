<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();

        // Filter by type if provided
        if ($request->has('type') && in_array($request->type, ['supplier', 'vendor'])) {
            $query->where('type', $request->type);
        }

        $suppliers = $query->paginate(15);
        return view('suppliers.index', compact('suppliers'));
    }

    public function create(Request $request)
    {
        $type = $request->get('type', Supplier::TYPE_SUPPLIER);
        return view('suppliers.create', compact('type'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:supplier,vendor',
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
        ]);

        Supplier::create($validated);

        return redirect()->route('suppliers.index')->with('success', ucfirst($validated['type']) . ' created successfully.');
    }

    public function show(Supplier $supplier)
    {
        return view('suppliers.show', compact('supplier'));
    }

    public function edit(Supplier $supplier)
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:supplier,vendor',
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
        ]);

        $supplier->update($validated);

        return redirect()->route('suppliers.index')->with('success', ucfirst($validated['type']) . ' updated successfully.');
    }

    public function destroy(Supplier $supplier)
    {
        $type = $supplier->type;
        $supplier->delete();
        return redirect()->route('suppliers.index')->with('success', ucfirst($type) . ' deleted successfully.');
    }
}

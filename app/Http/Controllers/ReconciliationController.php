<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function index()
    {
        return view('reconciliation.index');
    }

    public function import()
    {
        return view('reconciliation.import');
    }

    public function processImport(Request $request)
    {
        $request->validate([
            'wise_csv' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        // Placeholder for Wise CSV import logic
        // This will be implemented in Phase 6

        return redirect()->route('reconciliation.index')
            ->with('info', 'Wise import functionality will be available in Phase 6.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\FrankingAccountEntry;
use App\Services\FrankingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * The notional franking account: manual entries (income tax paid, refunds,
 * FDT paid, adjustments, estimated AASB 1054.13 movements), the computed
 * running balance and the year-end deficit warning. FD entries are created
 * by DividendService when a dividend run is recorded paid and cannot be
 * edited here.
 */
class FrankingAccountController extends Controller
{
    public function index(Request $request)
    {
        $years = FrankingService::years();
        $year = (int) ($request->query('year', $years[0] ?? now()->year));
        if (! in_array($year, $years, true)) {
            $year = (int) ($years[0] ?? now()->year);
        }

        // Running balance is computed across the lifetime of the account —
        // entries before the selected year carry forward into it.
        $carryForward = FrankingService::openingBalance($year);
        $running = $carryForward;

        $entries = FrankingAccountEntry::query()
            ->whereDate('entry_date', '<=', FrankingService::yearBounds($year)['end']->toDateString())
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->each(function ($entry) use (&$running) {
                $entry->running_balance = $entry->is_estimated
                    ? null // estimated entries never move the actual balance
                    : round($running += $entry->netAmount(), 2);
            });

        return view('franking-account.index', [
            'year' => $year,
            'years' => $years,
            'entries' => $entries,
            'carryForward' => $carryForward,
            'closingBalance' => FrankingService::closingBalance($year),
            'openingBalance' => $carryForward,
            'availableBalance' => FrankingService::availableBalance(FrankingService::yearBounds($year)['end']),
            'movements' => FrankingService::movementsByType($year),
            'deficit' => config('dividends.enable_fdt_warning') && FrankingService::hasDeficit($year),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'entry_type' => ['required', 'in:'.implode(',', FrankingAccountEntry::MANUAL_TYPES)],
            'entry_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:100'],
            'credit_amount' => ['nullable', 'numeric', 'min:0'],
            'debit_amount' => ['nullable', 'numeric', 'min:0'],
            'is_estimated' => ['nullable', 'boolean'],
        ]);

        try {
            FrankingService::recordEntry($validated);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('franking-account.index')->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('franking-account.index')
            ->with('success', 'Franking account entry recorded.');
    }

    public function destroy(FrankingAccountEntry $entry)
    {
        if (! in_array($entry->entry_type, FrankingAccountEntry::MANUAL_TYPES, true)) {
            return redirect()->route('franking-account.index')
                ->with('error', 'System-generated franking entries cannot be deleted.');
        }

        $entry->delete();

        return redirect()->route('franking-account.index')
            ->with('success', 'Franking account entry deleted.');
    }

    /**
     * AASB 1054.13 franking credit disclosure for a financial year.
     */
    public function disclosure(Request $request)
    {
        $years = FrankingService::years();
        $year = (int) ($request->query('year', $years[0] ?? now()->year));

        return view('franking-account.disclosure', [
            'data' => FrankingService::disclosureData($year),
            'years' => $years,
        ]);
    }

    public function disclosurePdf(Request $request)
    {
        $years = FrankingService::years();
        $year = (int) ($request->query('year', $years[0] ?? now()->year));

        $pdf = Pdf::loadView('reports.pdf.franking-disclosure', [
            'data' => FrankingService::disclosureData($year),
        ]);

        return $pdf->download("Franking-Disclosure-FY{$year}.pdf");
    }
}

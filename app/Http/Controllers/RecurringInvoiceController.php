<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecurringInvoiceController extends Controller
{
    public function create(Request $request)
    {
        $clients = Client::orderBy('name')->get();
        $clientId = $request->query('client');

        return view('recurring-invoices.create', [
            'clients' => $clients,
            'clientId' => $clientId,
            'frequencies' => [
                'daily' => 'Daily',
                'weekly' => 'Weekly',
                'monthly' => 'Monthly',
                'yearly' => 'Yearly',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'frequency' => 'required|string',
            'start_date' => 'required|date',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $frequency = strtolower($validated['frequency']);
            $startDate = Carbon::parse($validated['start_date']);
            $nextDate = $this->calculateNextRecurringDate($startDate, $frequency);

            $invoice = Invoice::create([
                'client_id' => $validated['client_id'],
                'created_by' => Auth::id(),
                'issue_date' => $startDate->toDateString(),
                'due_date' => $startDate->copy()->addDays(30)->toDateString(),
                'status' => Invoice::STATUS_DRAFT,
                'is_recurring' => true,
                'recurring_frequency' => $frequency,
                'next_recurring_date' => $nextDate->toDateString(),
                'recurring_status' => Invoice::RECURRING_ACTIVE,
                'terms' => config('australian.invoice_terms'),
            ]);

            foreach ($validated['items'] as $index => $item) {
                $invoice->items()->create([
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => 0,
                    'discount_percent' => 0,
                    'sort_order' => $index,
                ]);
            }

            $invoice->recalculateTotals();

            DB::commit();

            return redirect()->route('recurring-invoices.show', $invoice)
                ->with('success', 'Recurring invoice created');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error creating recurring invoice: ' . $e->getMessage());
        }
    }

    public function show(Invoice $recurringInvoice)
    {
        $recurringInvoice->load(['client', 'items']);
        return view('recurring-invoices.show', ['recurringInvoice' => $recurringInvoice]);
    }

    public function edit(Invoice $recurringInvoice)
    {
        $recurringInvoice->load(['client', 'items']);
        $clients = Client::orderBy('name')->get();

        return view('recurring-invoices.edit', [
            'recurringInvoice' => $recurringInvoice,
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, Invoice $recurringInvoice)
    {
        $validated = $request->validate([
            'items.*.description' => 'nullable|string',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        foreach ($validated['items'] ?? [] as $index => $item) {
            $recurringInvoice->items()
                ->where('sort_order', $index)
                ->update([
                    'description' => $item['description'] ?? null,
                    'unit_price' => $item['unit_price'] ?? null,
                ]);
        }

        $recurringInvoice->recalculateTotals();

        return redirect()->route('recurring-invoices.show', $recurringInvoice)
            ->with('success', 'Recurring invoice updated');
    }

    public function pause(Invoice $recurringInvoice)
    {
        $recurringInvoice->update(['recurring_status' => Invoice::RECURRING_PAUSED]);

        return redirect()->route('recurring-invoices.show', $recurringInvoice)
            ->with('success', 'Recurring schedule paused');
    }

    public function resume(Invoice $recurringInvoice)
    {
        $recurringInvoice->update(['recurring_status' => Invoice::RECURRING_ACTIVE]);

        return redirect()->route('recurring-invoices.show', $recurringInvoice)
            ->with('success', 'Recurring schedule resumed');
    }

    public function destroy(Invoice $recurringInvoice)
    {
        $recurringInvoice->update(['recurring_status' => Invoice::RECURRING_STOPPED]);

        return redirect()->route('recurring-invoices.index')
            ->with('success', 'Recurring invoice removed');
    }

    public function index()
    {
        $recurringInvoices = Invoice::where('is_recurring', true)
            ->where('recurring_status', '!=', Invoice::RECURRING_STOPPED)
            ->with('client')
            ->latest()
            ->get();

        return view('recurring-invoices.index', ['recurringInvoices' => $recurringInvoices]);
    }

    private function calculateNextRecurringDate(Carbon $currentDate, ?string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $currentDate->copy()->addDay(),
            'weekly' => $currentDate->copy()->addWeek(),
            'monthly' => $currentDate->copy()->addMonth(),
            'quarterly' => $currentDate->copy()->addMonths(3),
            'yearly' => $currentDate->copy()->addYear(),
            default => $currentDate->copy()->addMonth(),
        };
    }
}

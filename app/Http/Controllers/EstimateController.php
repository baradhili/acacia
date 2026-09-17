<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Models\Lead;

class EstimateController extends Controller
{
    /**
     * Shared validation for store/update: base fields plus the line
     * item shape — lines may reference a catalogue Service (the link
     * is kept even when description/price are tailored), carry a
     * section label for grouping, and be flagged optional (quoted as
     * an extra outside the committed total).
     */
    protected function estimateRules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
            'issue_date' => 'required|date',
            'valid_until' => 'required|date|after_or_equal:issue_date',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.service_id' => 'nullable|exists:services,id',
            'items.*.section' => 'nullable|string|max:100',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.is_optional' => 'nullable|boolean',
            'lead_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Persist the submitted lines (store and update share the shape).
     */
    protected function createItems(Estimate $estimate, array $items): void
    {
        foreach ($items as $index => $item) {
            $estimate->items()->create([
                'service_id' => $item['service_id'] ?? null,
                'section' => $item['section'] ?? null,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_rate' => $item['tax_rate'] ?? config('australian.gst.rate', 10),
                'discount_percent' => $item['discount_percent'] ?? 0,
                'sort_order' => $index,
                'is_optional' => (bool) ($item['is_optional'] ?? false),
            ]);
        }
    }

    public function index(Request $request)
    {
        $query = Estimate::with(['client', 'project'])->withCount('documents');

        // Filter by status
        if ($request->has('status') && $request->status) {
            if ($request->status === 'expired') {
                $query->expired();
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by client
        if ($request->has('client_id') && $request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        $estimates = $query->latest()->paginate(15);
        $clients = Client::orderBy('name')->pluck('name', 'id');

        return view('estimates.index', compact('estimates', 'clients'));
    }

    public function create(Request $request)
    {
        $clients = Client::orderBy('name')->pluck('name', 'id');
        $projects = Project::with('client')->get()->groupBy('client_id');
        $services = Service::orderBy('name')->get();

        $selectedClient = $request->client_id ? Client::find($request->client_id) : null;
        $selectedProject = $request->project_id ? Project::find($request->project_id) : null;

        // Soft dependency: the CRM module's proposal-stage shortcut
        // sends a lead whose plan, value and first line pre-fill the
        // form (and the created estimate links back to the lead).
        $lead = null;
        if ($request->lead_id && class_exists(Lead::class)) {
            $lead = Lead::query()->open()->find($request->lead_id);
        }

        return view('estimates.create', compact(
            'clients',
            'projects',
            'services',
            'selectedClient',
            'selectedProject',
            'lead'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->estimateRules());

        DB::beginTransaction();
        try {
            $estimate = Estimate::create([
                'client_id' => $validated['client_id'],
                'project_id' => $validated['project_id'] ?? null,
                'created_by' => Auth::id(),
                'issue_date' => $validated['issue_date'],
                'valid_until' => $validated['valid_until'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? config('australian.estimate_terms'),
            ]);

            $this->createItems($estimate, $validated['items']);

            // Link the estimate back to the lead it was prepared from.
            if (! empty($validated['lead_id']) && class_exists(Lead::class)) {
                Lead::query()->open()->whereKey($validated['lead_id'])
                    ->update(['estimate_id' => $estimate->id]);
            }

            $estimate->recalculateTotals();

            DB::commit();

            return redirect()->route('estimates.show', $estimate)
                ->with('success', 'Estimate created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error creating estimate: '.$e->getMessage());
        }
    }

    public function show(Estimate $estimate)
    {
        $estimate->load(['client', 'project', 'creator', 'items.service', 'documents']);

        return view('estimates.show', compact('estimate'));
    }

    public function edit(Estimate $estimate)
    {
        if ($estimate->status !== Estimate::STATUS_DRAFT) {
            return redirect()->route('estimates.show', $estimate)
                ->with('error', 'Only draft estimates can be edited.');
        }

        $clients = Client::orderBy('name')->pluck('name', 'id');
        $projects = Project::with('client')->get()->groupBy('client_id');
        $services = Service::orderBy('name')->get();

        return view('estimates.edit', compact('estimate', 'clients', 'projects', 'services'));
    }

    public function update(Request $request, Estimate $estimate)
    {
        if ($estimate->status !== Estimate::STATUS_DRAFT) {
            return redirect()->route('estimates.show', $estimate)
                ->with('error', 'Only draft estimates can be edited.');
        }

        $validated = $request->validate($this->estimateRules());

        DB::beginTransaction();
        try {
            $estimate->update([
                'client_id' => $validated['client_id'],
                'project_id' => $validated['project_id'] ?? null,
                'issue_date' => $validated['issue_date'],
                'valid_until' => $validated['valid_until'],
                'notes' => $validated['notes'] ?? null,
                'terms' => $validated['terms'] ?? null,
            ]);

            // Delete existing items and recreate
            $estimate->items()->delete();

            $this->createItems($estimate, $validated['items']);

            // Link the estimate back to the lead it was prepared from.
            if (! empty($validated['lead_id']) && class_exists(Lead::class)) {
                Lead::query()->open()->whereKey($validated['lead_id'])
                    ->update(['estimate_id' => $estimate->id]);
            }

            $estimate->recalculateTotals();

            DB::commit();

            return redirect()->route('estimates.show', $estimate)
                ->with('success', 'Estimate updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Error updating estimate: '.$e->getMessage());
        }
    }

    public function destroy(Estimate $estimate)
    {
        if ($estimate->status !== Estimate::STATUS_DRAFT) {
            return redirect()->route('estimates.index')
                ->with('error', 'Only draft estimates can be deleted.');
        }

        $estimate->delete();

        return redirect()->route('estimates.index')
            ->with('success', 'Estimate deleted successfully.');
    }

    public function send(Estimate $estimate)
    {
        if ($estimate->status !== Estimate::STATUS_DRAFT) {
            return back()->with('error', 'Only draft estimates can be sent.');
        }

        $estimate->markAsSent();

        return back()->with('success', 'Estimate marked as sent.');
    }

    public function accept(Estimate $estimate)
    {
        if ($estimate->status !== Estimate::STATUS_SENT) {
            return back()->with('error', 'Only sent estimates can be accepted.');
        }

        $estimate->accept();

        return back()->with('success', 'Estimate accepted.');
    }

    public function reject(Estimate $estimate)
    {
        if ($estimate->status !== Estimate::STATUS_SENT) {
            return back()->with('error', 'Only sent estimates can be rejected.');
        }

        $estimate->reject();

        return back()->with('success', 'Estimate rejected.');
    }

    /**
     * Convert estimate to invoice — optional lines ride along only
     * when the caller ticks "include optional items".
     */
    public function convertToInvoice(Request $request, Estimate $estimate)
    {
        if ($estimate->status !== Estimate::STATUS_ACCEPTED) {
            return back()->with('error', 'Only accepted estimates can be converted to invoices.');
        }

        $invoice = $estimate->convertToInvoice($request->boolean('include_optional'));

        if ($invoice) {
            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Estimate converted to invoice successfully.');
        }

        return back()->with('error', 'Could not convert estimate to invoice.');
    }

    /**
     * Duplicate estimate as new draft
     */
    public function duplicate(Estimate $estimate)
    {
        DB::beginTransaction();
        try {
            $newEstimate = $estimate->replicate();
            $newEstimate->estimate_number = null; // Will generate new number
            $newEstimate->status = Estimate::STATUS_DRAFT;
            $newEstimate->issue_date = now()->toDateString();
            $newEstimate->valid_until = now()->addDays(30)->toDateString();
            $newEstimate->converted_at = null;
            $newEstimate->converted_to_invoice_id = null;
            $newEstimate->save();

            // Copy items
            foreach ($estimate->items as $item) {
                $newItem = $item->replicate();
                $newItem->estimate_id = $newEstimate->id;
                $newItem->save();
            }

            $newEstimate->recalculateTotals();

            DB::commit();

            return redirect()->route('estimates.edit', $newEstimate)
                ->with('success', 'Estimate duplicated. You can now edit it.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Error duplicating estimate: '.$e->getMessage());
        }
    }
}

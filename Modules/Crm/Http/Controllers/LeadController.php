<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadActivity;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::with('owner');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('owner_id')) {
            $query->where('owner_id', $request->owner_id);
        }

        $leads = $query->orderByDesc('updated_at')->paginate(15)->withQueryString();

        // Funnel stats: count and value per stage over the open
        // pipeline, plus the weighted forecast.
        $funnel = collect(Lead::OPEN_STATUSES)
            ->mapWithKeys(fn ($status) => [$status => [
                'count' => Lead::where('status', $status)->count(),
                'value' => round((float) Lead::where('status', $status)->sum('estimated_value'), 2),
            ]]);
        $pipelineValue = round((float) Lead::whereIn('status', Lead::OPEN_STATUSES)->sum('estimated_value'), 2);
        $forecast = round((float) Lead::whereIn('status', Lead::OPEN_STATUSES)
            ->selectRaw('SUM(estimated_value * probability / 100) as weighted')->value('weighted'), 2);

        return view('crm.leads.index', [
            'leads' => $leads,
            'funnel' => $funnel,
            'pipelineValue' => $pipelineValue,
            'forecast' => $forecast,
            'overdue' => Lead::whereIn('status', Lead::OPEN_STATUSES)
                ->whereDate('next_follow_up', '<', today())->count(),
            'owners' => User::orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['status', 'source', 'owner_id']),
        ]);
    }

    public function create()
    {
        return view('crm.leads.create', [
            'owners' => User::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        Lead::create($validated + ['owner_id' => $validated['owner_id'] ?? Auth::id()]);

        return redirect()->route('crm.leads.index')
            ->with('success', 'Lead created.');
    }

    public function show(Lead $lead)
    {
        $lead->load(['activities.user', 'owner', 'client']);

        return view('crm.leads.show', ['lead' => $lead]);
    }

    public function edit(Lead $lead)
    {
        return view('crm.leads.edit', [
            'lead' => $lead,
            'owners' => User::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request, Lead $lead)
    {
        $validated = $request->validate($this->rules());

        $lead->update($validated);

        return redirect()->route('crm.leads.show', $lead)
            ->with('success', 'Lead updated.');
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();

        return redirect()->route('crm.leads.index')
            ->with('success', 'Lead deleted.');
    }

    /**
     * Move a lead along the funnel; losing requires a reason and
     * clears the follow-up plan.
     */
    public function updateStatus(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:'.implode(',', Lead::STATUSES)],
            'loss_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $lead->canTransitionTo($validated['status'])) {
            return back()->with('error', "A {$lead->label()} lead cannot move to {$validated['status']}.");
        }

        $lead->update([
            'status' => $validated['status'],
            'loss_reason' => $validated['status'] === Lead::STATUS_LOST ? ($validated['loss_reason'] ?? 'Not specified') : null,
            'next_follow_up' => $validated['status'] === Lead::STATUS_LOST ? null : $lead->next_follow_up,
        ]);

        return back()->with('success', "Lead moved to {$validated['status']}.");
    }

    /**
     * Winning: convert the lead to a Client. The lead keeps pointing
     * at the client it became; the name/email/phone carry over.
     */
    public function convert(Request $request, Lead $lead)
    {
        if (! $lead->canTransitionTo(Lead::STATUS_WON)) {
            return back()->with('error', 'Only proposal-stage leads can be converted to a client.');
        }

        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
        ]);

        $client = Client::create([
            'name' => $validated['client_name'],
            'email' => $lead->email,
            'phone' => $lead->phone,
        ]);

        $lead->update([
            'status' => Lead::STATUS_WON,
            'client_id' => $client->id,
            'converted_at' => now(),
            'loss_reason' => null,
        ]);

        $lead->activities()->create([
            'user_id' => Auth::id(),
            'type' => 'note',
            'summary' => "Converted to client {$client->name}.",
            'happened_at' => now(),
        ]);

        return redirect()->route('clients.show', $client)
            ->with('success', "Lead converted — {$client->name} is now a client.");
    }

    public function storeActivity(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:'.implode(',', LeadActivity::TYPES)],
            'summary' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string'],
            'happened_at' => ['nullable', 'date'],
        ]);

        $lead->activities()->create($validated + [
            'user_id' => Auth::id(),
            'happened_at' => $validated['happened_at'] ?? now(),
        ]);

        return back()->with('success', 'Activity logged.');
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'in:'.implode(',', Lead::sources())],
            'notes' => ['nullable', 'string'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'probability' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'next_follow_up' => ['nullable', 'date'],
        ];
    }
}

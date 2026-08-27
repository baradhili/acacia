<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Prepayment;
use App\Services\IfrsPosting;
use Carbon\Carbon;
use IFRS\Models\Account;
use Illuminate\Http\Request;

/**
 * Domain name registry. Initial purchases are capitalised by coding a
 * bill line to the intangible account (170 — appears automatically in
 * the bills "Capital purchases" group); this registry tracks each
 * domain, flags renewal due dates, and creates amortisation schedules
 * for finite-life domains (Cr 170 / Dr 7910). Renewals must be EXPENSED
 * (7510) — the show page's renewal link prefills a bill accordingly.
 * Admin/accountant only.
 */
class DomainController extends Controller
{
    public function index()
    {
        $domains = Domain::with('account')->orderBy('name')->get();

        return view('domains.index', compact('domains'));
    }

    public function create()
    {
        return view('domains.create', ['domain' => new Domain(['indefinite_life' => true, 'status' => Domain::STATUS_ACTIVE])]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        Domain::create($validated + ['entity_id' => IfrsPosting::resolveEntity()?->id]);

        return redirect()->route('domains.index')->with('success', 'Domain added to the registry.');
    }

    public function show(Domain $domain)
    {
        $domain->load('account', 'prepayments');

        $renewalAccountId = $this->accountByCode(config('subscriptions.domain_renewal_expense_code', 7510))?->id;

        return view('domains.show', compact('domain', 'renewalAccountId'));
    }

    public function edit(Domain $domain)
    {
        return view('domains.edit', compact('domain'));
    }

    public function update(Request $request, Domain $domain)
    {
        $validated = $this->validated($request);

        $domain->update($validated);

        return redirect()->route('domains.show', $domain)->with('success', 'Domain updated.');
    }

    public function destroy(Domain $domain)
    {
        $domain->update(['status' => Domain::STATUS_RETIRED]);

        return redirect()->route('domains.index')->with('success', "{$domain->name} retired from the registry.");
    }

    /**
     * Create the amortisation schedule for a finite-life domain: the
     * capitalised cost (asset 170) is expensed to 7910 over the useful
     * life, reusing the prepayment runner.
     */
    public function createAmortisation(Request $request, Domain $domain)
    {
        $reject = fn (string $message) => redirect()
            ->route('domains.show', $domain)
            ->with('error', $message);

        if ($domain->indefinite_life) {
            return $reject('Indefinite-life domains are not amortised.');
        }
        if (!$domain->useful_life_months || $domain->useful_life_months < 1) {
            return $reject('Set a useful life (months) before creating the schedule.');
        }
        if ((float) $domain->cost <= 0) {
            return $reject('Set the capitalised cost before creating the schedule.');
        }
        if ($domain->prepayments()->where('status', '!=', Prepayment::STATUS_VOID)->exists()) {
            return $reject('An amortisation schedule already exists for this domain.');
        }

        $entity = IfrsPosting::resolveEntity();
        abort_unless((bool) $entity, 404, 'No IFRS entity configured.');

        $assetAccount = $domain->account_id
            ? Account::find($domain->account_id)
            : $this->accountByCode(config('subscriptions.domain_intangible_code', 170));
        $expenseAccount = $this->accountByCode(config('subscriptions.amortisation_expense_code', 7910));

        if (!$assetAccount || !$expenseAccount) {
            return $reject('Intangible (170) or Amortisation Expense (7910) accounts are not seeded.');
        }

        $start = $domain->purchased_at?->copy() ?? now();
        $end = $start->copy()->addMonths($domain->useful_life_months)->subDay();
        $periods = $domain->useful_life_months;
        $total = (float) $domain->cost;

        $domain->prepayments()->create([
            'entity_id' => $entity->id,
            'description' => "Domain amortisation: {$domain->name}",
            'asset_account_id' => $assetAccount->id,
            'expense_account_id' => $expenseAccount->id,
            'service_start' => $start->toDateString(),
            'service_end' => $end->toDateString(),
            'periods' => $periods,
            'total_amount' => $total,
            'monthly_amount' => round($total / $periods, 2),
            'next_period_date' => $start->copy()->endOfMonth()->toDateString(),
            'status' => Prepayment::STATUS_ACTIVE,
        ]);

        return redirect()->route('prepayments.index')
            ->with('success', "Amortisation schedule created for {$domain->name} — the runner will post {$periods} monthly entries.");
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'registrar' => 'nullable|string|max:100',
            'purchased_at' => 'nullable|date',
            'expiry_date' => 'nullable|date|after_or_equal:purchased_at',
            'cost' => 'nullable|numeric|min:0',
            'indefinite_life' => 'nullable|boolean',
            'useful_life_months' => 'nullable|integer|min:1|required_if:indefinite_life,0',
            'notes' => 'nullable|string',
        ]);
    }

    protected function accountByCode(int $code): ?Account
    {
        $entity = IfrsPosting::resolveEntity();

        return $entity
            ? Account::where('entity_id', $entity->id)->where('code', $code)->first()
            : null;
    }
}

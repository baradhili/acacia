<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Document;
use Illuminate\Http\Request;
use IFRS\Models\Entity;

class ClientController extends Controller
{
    public function index()
    {
        $clients = Client::paginate(15);
        return view('clients.index', compact('clients'));
    }

    public function create()
    {
        return view('clients.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postcode' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'abn' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
        ]);

        Client::create($validated);

        return redirect()->route('clients.index')->with('success', 'Client created successfully.');
    }

    public function show(Client $client)
    {
        // Get IFRS Entity linked to this client
        $entity = Entity::where('name', 'like', '%' . $client->name . '%')->first();

        // Get recent transactions if entity exists
        $transactions = collect();
        $aging = [
            'current' => 0,
            'days_30' => 0,
            'days_60' => 0,
            'days_90' => 0,
            'over_90' => 0,
        ];

        if ($entity) {
            // Get ledgers for this entity (receivables)
            $ledgers = $entity->ledgers()->with('account')->get();

            // Group by aging buckets
            $now = now();
            foreach ($ledgers as $ledger) {
                $amount = abs($ledger->amount);
                $date = $ledger->created_at;

                if ($date->diffInDays($now) <= 30) {
                    $aging['current'] += $amount;
                } elseif ($date->diffInDays($now) <= 60) {
                    $aging['days_30'] += $amount;
                } elseif ($date->diffInDays($now) <= 90) {
                    $aging['days_60'] += $amount;
                } elseif ($date->diffInDays($now) <= 90) {
                    $aging['days_90'] += $amount;
                } else {
                    $aging['over_90'] += $amount;
                }
            }

            // Get recent transactions
            $transactions = $ledgers->sortByDesc('created_at')->take(10);
        }

        // Get documents
        $documents = $client->documents()->with('uploadedBy')->latest()->get();

        return view('clients.show', compact('client', 'transactions', 'aging', 'documents'));
    }

    public function edit(Client $client)
    {
        return view('clients.edit', compact('client'));
    }

    public function update(Request $request, Client $client)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postcode' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'abn' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
        ]);

        $client->update($validated);

        return redirect()->route('clients.index')->with('success', 'Client updated successfully.');
    }

    public function destroy(Client $client)
    {
        $client->delete();
        return redirect()->route('clients.index')->with('success', 'Client deleted successfully.');
    }
}

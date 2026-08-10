<?php

namespace App\Http\Controllers;

use IFRS\Transactions\JournalEntry;
use IFRS\Models\Account;
use IFRS\Models\LineItem;
use Illuminate\Http\Request;

class JournalEntryController extends Controller
{
    public function index()
    {
        $entries = collect();

        try {
            $entries = JournalEntry::withoutGlobalScopes()
                ->with(['lineItems.account'])
                ->where('transaction_type', JournalEntry::PREFIX)
                ->orderBy('transaction_date', 'desc')
                ->get();
        } catch (\Throwable $e) {
            $entries = collect();
        }

        return view('journal-entries.index', compact('entries'));
    }

    public function create()
    {
        $accounts = collect();

        try {
            $accounts = Account::withoutGlobalScopes()->orderBy('name')->pluck('name', 'id');
        } catch (\Throwable $e) {
            $accounts = collect();
        }

        return view('journal-entries.create', compact('accounts'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string',
            'date' => 'nullable|date',
            'debit_account' => 'required|exists:' . config('ifrs.table_prefix') . 'accounts,id',
            'debit_amount' => 'required|numeric|min:0.01',
            'credit_account' => 'required|exists:' . config('ifrs.table_prefix') . 'accounts,id',
            'credit_amount' => 'required|numeric|min:0.01',
        ]);

        if ((float) $validated['debit_amount'] !== (float) $validated['credit_amount']) {
            return back()->withErrors(['debit_amount' => 'Debits must equal credits'])->withInput();
        }

        $journalEntry = new JournalEntry([
            'date' => $validated['date'] ?? now(),
            'narration' => $validated['description'],
        ]);

        $journalEntry->addLineItem(
            LineItem::create([
                'account_id' => $validated['debit_account'],
                'amount' => $validated['debit_amount'],
                'type' => LineItem::DEBIT,
                'tax_rate' => 0,
            ])
        );

        $journalEntry->addLineItem(
            LineItem::create([
                'account_id' => $validated['credit_account'],
                'amount' => $validated['credit_amount'],
                'type' => LineItem::CREDIT,
                'tax_rate' => 0,
            ])
        );

        $journalEntry->save();

        return redirect()->route('journal-entries.index')
            ->with('success', 'Journal entry created successfully');
    }
}

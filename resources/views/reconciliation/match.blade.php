@extends('layouts.app')
@section('title', 'Match Bank Transaction')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Match Bank Transaction</h1>
            <p class="text-sm text-gray-500 mt-1">
                Pick what this bank movement corresponds to — the match is remembered per payer/payee
                so future lines from the same counterparty can auto-match.
            </p>
        </div>
        <a href="{{ route('reconciliation.index') }}"
            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 text-sm">Back to reconciliation</a>
    </div>

    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-500">
                    {{ $transaction->transaction_date?->format('d M Y') }} ·
                    <span class="{{ $transaction->amount < 0 ? 'text-red-600' : 'text-green-700' }}">
                        {{ $transaction->amount < 0 ? '-' : '' }}${{ number_format(abs((float) $transaction->amount), 2) }} {{ $transaction->currency }}
                    </span>
                </p>
                <p class="text-gray-900 mt-1">{{ $transaction->description }}</p>
                <p class="text-sm text-gray-500 mt-1">
                    {{ $transaction->payer_name ?? $transaction->payee_name ?? '—' }}
                    @if ($transaction->reference) · reference {{ $transaction->reference }} @endif
                </p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 flex flex-wrap justify-between items-center gap-3">
            <h2 class="text-lg font-semibold text-gray-800">Candidates</h2>
            <form method="GET" class="flex items-end gap-2">
                <div>
                    <label for="q" class="block text-xs font-medium text-gray-500 mb-1">
                        Search by reference or name (any amount, ±60 days)
                    </label>
                    <input type="text" name="q" id="q" value="{{ $search }}" maxlength="100"
                        placeholder="e.g. INV-2026, Acme, AWS"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
                <button type="submit"
                    class="px-3 py-2 bg-slate-600 text-white text-sm rounded-md hover:bg-slate-700 shrink-0">Search</button>
            </form>
        </div>

        @if ($search === '')
            <p class="px-4 py-2 text-xs text-gray-500 bg-gray-50 border-b border-gray-100">
                Amount-close candidates within ±14 days of the bank date.
            </p>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Counterparty</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($candidates as $candidate)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-800">
                                    {{ ucfirst($candidate['type']) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $candidate['reference'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ $candidate['client'] ?? $candidate['supplier'] ?? $candidate['account'] ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                                {{ \Carbon\Carbon::parse($candidate['date'])->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right whitespace-nowrap {{ (float) $candidate['amount'] === (float) $transaction->amount ? 'text-gray-900' : 'text-amber-700 font-medium' }}">
                                ${{ number_format(abs((float) $candidate['amount']), 2) }}
                                @if ((float) $candidate['amount'] !== (float) $transaction->amount)
                                    <span class="block text-xs font-normal text-gray-400">differs from bank line</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form action="{{ route('reconciliation.match.store', $transaction) }}" method="POST" class="inline">
                                    @csrf
                                    <input type="hidden" name="type" value="{{ $candidate['type'] }}">
                                    <input type="hidden" name="target_id" value="{{ $candidate['id'] }}">
                                    <button class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">Match</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-sm text-gray-500">
                                @if ($search === '')
                                    No amount-close candidates within ±14 days — try the search, or match by id below.
                                @else
                                    Nothing matches "{{ $search }}" within ±60 days — try another term, or match by id below.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Match by id</h2>
        <p class="text-sm text-gray-500 mb-4">
            For anything the search can't surface — an older payment, a journal entry — enter its type and id directly.
        </p>
        <form action="{{ route('reconciliation.match.store', $transaction) }}" method="POST" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="manual_type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="type" id="manual_type"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="payment">Payment</option>
                    <option value="invoice">Invoice</option>
                    <option value="bill">Bill</option>
                    <option value="ledger">Ledger entry</option>
                </select>
            </div>
            <div>
                <label for="target_id" class="block text-sm font-medium text-gray-700 mb-1">Id</label>
                <input type="number" name="target_id" id="target_id" min="1" required
                    class="w-32 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div class="flex-1 min-w-[240px]">
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <input type="text" name="notes" id="notes" maxlength="500"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">Match</button>
        </form>
    </div>
@endsection

@extends('layouts.app')
@section('title', 'Bank Reconciliation')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Bank Reconciliation</h1>
            <p class="text-sm text-gray-500 mt-1">
                Import your bank's transaction CSV export, then match each movement against invoices, payments and bills.
            </p>
        </div>
        <div class="flex gap-2">
            <form action="{{ route('reconciliation.auto-match') }}" method="POST">
                @csrf
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm"
                    @if ($stats['pending'] === 0) disabled title="Nothing pending" @endif>
                    Auto-match pending
                </button>
            </form>
            <a href="{{ route('reconciliation.import') }}"
                class="px-4 py-2 bg-gray-800 text-white rounded-md hover:bg-gray-900 text-sm">Import CSV</a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500">Pending</p>
            <p class="text-2xl font-bold text-amber-600">{{ number_format($stats['pending']) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500">Matched</p>
            <p class="text-2xl font-bold text-green-600">{{ number_format($stats['matched']) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500">Ignored</p>
            <p class="text-2xl font-bold text-gray-500">{{ number_format($stats['ignored']) }}</p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-800">Pending transactions</h2>
            <span class="text-xs text-gray-500">latest first</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payer / Payee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($pending as $transaction)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $transaction->transaction_date?->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $transaction->description }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $transaction->payer_name ?? $transaction->payee_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $transaction->reference ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-right whitespace-nowrap {{ $transaction->amount < 0 ? 'text-red-600' : 'text-green-700' }}">
                                {{ $transaction->amount < 0 ? '-' : '' }}${{ number_format(abs((float) $transaction->amount), 2) }} {{ $transaction->currency }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form action="{{ route('reconciliation.ignore', $transaction) }}" method="POST" class="inline"
                                    onsubmit="return confirm('Ignore this transaction?');">
                                    @csrf
                                    <button class="text-gray-500 hover:text-red-600 text-sm font-medium">Ignore</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-sm text-gray-500">
                                Nothing pending — import a CSV export to bring in new bank movements.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800">Recently matched</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matched to</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">When</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($matched as $transaction)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $transaction->transaction_date?->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $transaction->description }}</td>
                            <td class="px-4 py-3 text-sm text-right whitespace-nowrap {{ $transaction->amount < 0 ? 'text-red-600' : 'text-green-700' }}">
                                {{ $transaction->amount < 0 ? '-' : '' }}${{ number_format(abs((float) $transaction->amount), 2) }} {{ $transaction->currency }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                    {{ ucfirst($transaction->matched_transaction_type ?? '?') }} #{{ $transaction->matched_transaction_id }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $transaction->matched_at?->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-sm text-gray-500">Nothing matched yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

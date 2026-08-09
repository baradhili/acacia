@extends('layouts.app')
@section('title', 'Wise Reconciliation')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Wise Reconciliation</h1>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Status Card -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Connection Status</p>
                    <p class="text-2xl font-bold text-yellow-600">Not Configured</p>
                </div>
                <div class="p-3 bg-yellow-100 rounded-full">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Pending Transactions</p>
                    <p class="text-2xl font-bold text-gray-800">{{ $pendingTransactions->count() }}</p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Last Reconciled</p>
                    <p class="text-2xl font-bold text-gray-800">Never</p>
                </div>
                <div class="p-3 bg-gray-100 rounded-full">
                    <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Transactions -->
    <div class="mt-6 bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">Pending Transactions</h2>
            <form method="POST" action="{{ route('reconciliation.auto-match') }}">
                @csrf
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                    Auto-Match
                </button>
            </form>
        </div>

        @if ($pendingTransactions->isEmpty())
            <p class="text-sm text-gray-500">No unmatched transactions.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($pendingTransactions as $transaction)
                            <tr data-transaction-id="{{ $transaction->id }}">
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $transaction->reference ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700">{{ $transaction->description ?? '—' }}</td>
                                <td class="px-4 py-2 text-sm text-right text-gray-700">{{ number_format($transaction->amount, 2) }} {{ $transaction->currency }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">{{ $transaction->status }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Instructions -->
    <div class="mt-6 bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Getting Started</h2>
        <div class="prose text-gray-600">
            <p>Wise Reconciliation allows you to import transactions from your Wise business account and match them against your internal records.</p>
            <ol class="list-decimal ml-6 mt-4 space-y-2">
                <li>Configure your Wise API credentials in the settings (coming in Phase 6)</li>
                <li>Import transactions from Wise or upload a CSV export</li>
                <li>Review and match transactions to invoices and expenses</li>
                <li>Create any missing transactions automatically</li>
            </ol>
        </div>
        <div class="mt-6">
            <a href="{{ route('reconciliation.import') }}" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                </svg>
                Import Wise CSV
            </a>
        </div>
    </div>

    <!-- Transactions List -->
    <div class="mt-6 bg-white rounded-lg shadow">
        <div class="p-6 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800">Bank Transactions</h2>
        </div>
        <div class="p-6">
            <table class="min-w-full divide-y divide-gray-200" id="bank-transactions-table">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($pendingTransactions as $transaction)
                        <tr id="transaction-{{ $transaction->id }}">
                            <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ optional($transaction->transaction_date)->format('Y-m-d') }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-800">{{ $transaction->description }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-500">{{ $transaction->reference ?? '-' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right font-medium {{ $transaction->amount >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format((float) $transaction->amount, 2) }} {{ $transaction->currency }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $transaction->status === \App\Models\BankTransaction::STATUS_MATCHED ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">{{ $transaction->status }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500">No transactions yet. Import a Wise CSV to get started.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Upcoming Features -->
    <div class="mt-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-100 p-6">
        <h3 class="text-lg font-semibold text-blue-800 mb-2">Phase 6 Preview</h3>
        <p class="text-blue-700">Full Wise API integration with automatic transaction matching and reconciliation will be available in Phase 6. This foundation prepares the system for those features.</p>
    </div>

@endsection
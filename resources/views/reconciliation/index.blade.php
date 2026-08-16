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
                    <p class="text-2xl font-bold text-gray-800">0</p>
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

    <!-- Instructions -->
    <div class="mt-6 bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Getting Started</h2>
        <div class="prose text-gray-600">
            <p>Wise Reconciliation allows you to import transactions from your Wise business account and match them against your internal records.</p>
            <ol class="list-decimal ml-6 mt-4 space-y-2">
                <li>Configure your Wise API credentials in the settings (coming in Phase 6)</li>
                <li>Import transactions from Wise or upload a CSV export</li>
                <li>Review and match transactions to invoices, bills and payments</li>
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

    <!-- Upcoming Features -->
    <div class="mt-6 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-100 p-6">
        <h3 class="text-lg font-semibold text-blue-800 mb-2">Phase 6 Preview</h3>
        <p class="text-blue-700">Full Wise API integration with automatic transaction matching and reconciliation will be available in Phase 6. This foundation prepares the system for those features.</p>
    </div>

@endsection
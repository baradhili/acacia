@extends('layouts.app')
@section('title', '{{ $client->name }}')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $client->name }}</h1>
        <div class="flex gap-3">
            <a href="{{ route('clients.edit', $client) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                Edit Client
            </a>
            <a href="{{ route('clients.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                Back
            </a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Contact Information</h3>
                <dl class="mt-4 space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Email:</dt>
                        <dd class="text-gray-900">{{ $client->email ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Phone:</dt>
                        <dd class="text-gray-900">{{ $client->phone ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">ABN:</dt>
                        <dd class="text-gray-900">{{ $client->abn ?? '-' }}</dd>
                    </div>
                </dl>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Address</h3>
                <dl class="mt-4 space-y-3">
                    <div>
                        <dt class="text-gray-600">Street:</dt>
                        <dd class="text-gray-900">{{ $client->address ?? '-' }}</dd>
                    </div>
                    <div class="flex gap-4">
                        <div>
                            <dt class="text-gray-600">City:</dt>
                            <dd class="text-gray-900">{{ $client->city ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">State:</dt>
                            <dd class="text-gray-900">{{ $client->state ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600">Postcode:</dt>
                            <dd class="text-gray-900">{{ $client->postcode ?? '-' }}</dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-gray-600">Country:</dt>
                        <dd class="text-gray-900">{{ $client->country ?? '-' }}</dd>
                    </div>
                </dl>
            </div>
        </div>
        @if($client->notes)
            <div class="mt-6 pt-6 border-t">
                <h3 class="text-sm font-medium text-gray-500">Notes</h3>
                <p class="mt-2 text-gray-700">{{ $client->notes }}</p>
            </div>
        @endif
    </div>

    <!-- AR Aging Summary -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">AR Aging Summary</h3>
        <div class="grid grid-cols-5 gap-4 text-center">
            <div class="p-4 bg-green-50 rounded-lg">
                <dt class="text-sm text-gray-600">Current</dt>
                <dd class="mt-1 text-lg font-semibold text-green-700">${{ number_format($aging['current'], 2) }}</dd>
            </div>
            <div class="p-4 bg-yellow-50 rounded-lg">
                <dt class="text-sm text-gray-600">1-30 Days</dt>
                <dd class="mt-1 text-lg font-semibold text-yellow-700">${{ number_format($aging['days_30'], 2) }}</dd>
            </div>
            <div class="p-4 bg-orange-50 rounded-lg">
                <dt class="text-sm text-gray-600">31-60 Days</dt>
                <dd class="mt-1 text-lg font-semibold text-orange-700">${{ number_format($aging['days_60'], 2) }}</dd>
            </div>
            <div class="p-4 bg-red-50 rounded-lg">
                <dt class="text-sm text-gray-600">61-90 Days</dt>
                <dd class="mt-1 text-lg font-semibold text-red-700">${{ number_format($aging['days_90'], 2) }}</dd>
            </div>
            <div class="p-4 bg-red-100 rounded-lg">
                <dt class="text-sm text-gray-600">90+ Days</dt>
                <dd class="mt-1 text-lg font-semibold text-red-800">${{ number_format($aging['over_90'], 2) }}</dd>
            </div>
        </div>
        <div class="mt-4 pt-4 border-t">
            <div class="flex justify-between items-center">
                <dt class="text-gray-600">Total Outstanding</dt>
                <dd class="text-xl font-bold text-gray-900">
                    ${{ number_format($aging['current'] + $aging['days_30'] + $aging['days_60'] + $aging['days_90'] + $aging['over_90'], 2) }}
                </dd>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Recent Transactions</h3>
        @if($transactions->isEmpty())
            <p class="text-gray-500 text-center py-4">No transactions found for this client.</p>
        @else
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($transactions as $transaction)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $transaction->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $transaction->account->name ?? 'N/A' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $transaction->entry_type }}</td>
                            <td class="px-4 py-3 text-sm text-right {{ $transaction->entry_type === 'debit' ? 'text-red-600' : 'text-green-600' }}">
                                ${{ number_format(abs($transaction->amount), 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <!-- Credit Notes -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Credit Notes</h3>
        </div>
        @if($client->creditNotes->isEmpty())
            <p class="text-gray-500 text-center py-4">No credit notes for this client.</p>
        @else
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">CN Number</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remaining</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($client->creditNotes as $cn)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('credit-notes.show', $cn) }}" class="text-indigo-600 hover:text-indigo-800">
                                    {{ $cn->credit_note_number }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $cn->issue_date->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                @if($cn->invoice)
                                    <a href="{{ route('invoices.show', $cn->invoice) }}" class="text-indigo-600 hover:text-indigo-800">
                                        {{ $cn->invoice->invoice_number }}
                                    </a>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-right text-orange-600">-${{ number_format($cn->total, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">${{ number_format($cn->remaining_amount, 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    @if($cn->status === 'applied') bg-green-100 text-green-800
                                    @elseif($cn->status === 'void') bg-gray-100 text-gray-500
                                    @else bg-blue-100 text-blue-800 @endif">
                                    {{ ucfirst($cn->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <!-- Attachments -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Attachments</h3>
            <a href="#" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                + Add Attachment
            </a>
        </div>
        @if($documents->isEmpty())
            <p class="text-gray-500 text-center py-4">No attachments for this client.</p>
        @else
            <ul class="divide-y divide-gray-200">
                @foreach($documents as $document)
                    <li class="py-3 flex items-center justify-between">
                        <div class="flex items-center">
                            <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span class="ml-2 text-sm text-gray-900">{{ $document->name }}</span>
                            <span class="ml-2 text-xs text-gray-500">({{ number_format($document->size / 1024, 1) }} KB)</span>
                        </div>
                        <div class="flex items-center text-sm text-gray-500">
                            <span>{{ $document->uploadedBy?->name ?? 'Unknown' }}</span>
                            <span class="mx-2">•</span>
                            <span>{{ $document->created_at->format('d M Y') }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

@endsection
@extends('layouts.app')
@section('title', $purchaseOrder->po_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $purchaseOrder->po_number }}</h1>
        <div class="flex gap-3">
            @if($purchaseOrder->status === 'draft')
                <a href="{{ route('purchase-orders.edit', $purchaseOrder) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Edit PO
                </a>
            @endif
            <a href="{{ route('purchase-orders.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                Back
            </a>
        </div>
    </div>

    <!-- PO Info -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Client</h3>
                <p class="mt-1 text-gray-900">{{ $purchaseOrder->client->name ?? '-' }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Title</h3>
                <p class="mt-1 text-gray-900">{{ $purchaseOrder->title }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Status</h3>
                @php
                    $statusColors = [
                        'draft' => 'bg-gray-100 text-gray-800',
                        'open' => 'bg-blue-100 text-blue-800',
                        'partially_used' => 'bg-yellow-100 text-yellow-800',
                        'completed' => 'bg-green-100 text-green-800',
                        'cancelled' => 'bg-red-100 text-red-800',
                    ];
                @endphp
                <span class="mt-1 inline-flex px-2 py-1 text-sm font-semibold rounded-full {{ $statusColors[$purchaseOrder->status] }}">
                    {{ ucfirst(str_replace('_', ' ', $purchaseOrder->status)) }}
                </span>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Dates</h3>
                <p class="mt-1 text-gray-900">
                    @if($purchaseOrder->start_date)
                        {{ $purchaseOrder->start_date->format('d M Y') }}
                        @if($purchaseOrder->end_date)
                            - {{ $purchaseOrder->end_date->format('d M Y') }}
                        @endif
                    @else
                        -
                    @endif
                </p>
            </div>
        </div>

        @if($purchaseOrder->status === 'draft' && $purchaseOrder->start_date && $purchaseOrder->start_date->isToday())
            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800">
                    <strong>Note:</strong> This PO will be automatically activated today.
                </p>
            </div>
        @elseif($purchaseOrder->status === 'draft' && $purchaseOrder->start_date && $purchaseOrder->start_date->isFuture())
            <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p class="text-sm text-yellow-800">
                    <strong>Scheduled:</strong> This PO will be automatically activated on {{ $purchaseOrder->start_date->format('d M Y') }}.
                </p>
            </div>
        @endif

        @if($purchaseOrder->description)
            <div class="mt-6 pt-6 border-t">
                <h3 class="text-sm font-medium text-gray-500">Description</h3>
                <p class="mt-1 text-gray-900">{{ $purchaseOrder->description }}</p>
            </div>
        @endif
    </div>

    <!-- Budget Summary -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Budgeted Amount</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">${{ number_format($purchaseOrder->budgeted_amount, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Used Amount</h3>
            <p class="mt-1 text-2xl font-bold text-yellow-600">${{ number_format($purchaseOrder->used_amount, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Remaining</h3>
            <p class="mt-1 text-2xl font-bold text-green-600">${{ number_format($purchaseOrder->remaining, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Utilization</h3>
            <p class="mt-1 text-2xl font-bold text-indigo-600">{{ number_format($purchaseOrder->utilization, 1) }}%</p>
        </div>
    </div>

    <!-- Actions -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Actions</h3>
        
        <div class="flex gap-3">
            @if($purchaseOrder->status === 'draft')
                <form action="{{ route('purchase-orders.activate', $purchaseOrder) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Activate PO
                    </button>
                </form>
            @endif

            @if(in_array($purchaseOrder->status, ['draft', 'open', 'partially_used']))
                <form action="{{ route('purchase-orders.cancel', $purchaseOrder) }}" method="POST" class="inline" onsubmit="return confirm('Cancel this PO?')">
                    @csrf
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Cancel PO
                    </button>
                </form>
            @endif
        </div>
    </div>

    <!-- Linked Time Entries -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Linked Time Entries</h3>
        </div>

        @if($purchaseOrder->timeEntries->count() > 0)
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($purchaseOrder->timeEntries as $entry)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $entry->start_time->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $entry->user->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ Str::limit($entry->description, 40) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format($entry->hours, 1) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-900">${{ number_format($entry->total, 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    @if($entry->status === 'approved') bg-green-100 text-green-800
                                    @elseif($entry->status === 'submitted') bg-yellow-100 text-yellow-800
                                    @else bg-gray-100 text-gray-800 @endif">
                                    {{ ucfirst($entry->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-gray-500 text-center py-4">No time entries linked to this purchase order.</p>
        @endif
    </div>

@endsection
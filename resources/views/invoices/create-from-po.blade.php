@extends('layouts.app')
@section('title', 'New Invoice from ' . $purchaseOrder->po_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">New Invoice from {{ $purchaseOrder->po_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $client?->name ?? 'No client' }}
                @if ($project)
                    &mdash; {{ $project->name }}
                @endif
            </p>
        </div>
        <a href="{{ route('purchase-orders.show', $purchaseOrder) }}"
            class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
            Back to PO
        </a>
    </div>

    <!-- PO summary -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500">PO Budget</h3>
                <p class="mt-1 text-xl font-bold text-gray-900">${{ number_format($purchaseOrder->budgeted_amount, 2) }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Used</h3>
                <p class="mt-1 text-xl font-bold text-yellow-600">${{ number_format($purchaseOrder->used_amount, 2) }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Remaining</h3>
                <p class="mt-1 text-xl font-bold text-green-600">${{ number_format($purchaseOrder->remaining, 2) }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Status</h3>
                <p class="mt-1 text-xl font-bold text-gray-900">{{ ucfirst(str_replace('_', ' ', $purchaseOrder->status)) }}</p>
            </div>
        </div>
    </div>

    <form action="{{ route('purchase-orders.create-invoice.store', $purchaseOrder) }}" method="POST" class="space-y-6">
        @csrf

        @include('invoices.partials.entry-picker', ['timeEntries' => $timeEntries])

        <div class="flex justify-end gap-4">
            <a href="{{ route('purchase-orders.show', $purchaseOrder) }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Create Invoice
            </button>
        </div>
    </form>

@endsection

@extends('layouts.app')
@section('title', 'Recurring Invoice')
@section('content')

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Recurring Invoice</h1>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
            {{ $recurringInvoice->recurring_status === 'active' ? 'bg-green-100 text-green-800' : '' }}
            {{ $recurringInvoice->recurring_status === 'paused' ? 'bg-yellow-100 text-yellow-800' : '' }}
            {{ $recurringInvoice->recurring_status === 'stopped' ? 'bg-red-100 text-red-800' : '' }}">
            {{ ucfirst($recurringInvoice->recurring_status) }}
        </span>
    </div>

    @if(session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <dl class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-gray-500">Client</dt>
                <dd class="font-medium text-gray-900">{{ $recurringInvoice->client->name }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Frequency</dt>
                <dd class="font-medium text-gray-900">{{ ucfirst($recurringInvoice->recurring_frequency) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Start Date</dt>
                <dd class="font-medium text-gray-900">{{ $recurringInvoice->issue_date->format('Y-m-d') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Next Invoice Date</dt>
                <dd class="font-medium text-gray-900">{{ $recurringInvoice->next_recurring_date?->format('Y-m-d') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Total</dt>
                <dd class="font-medium text-gray-900">${{ number_format($recurringInvoice->total, 2) }}</dd>
            </div>
        </dl>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Line Items</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-left text-gray-500">
                    <th class="py-2">Description</th>
                    <th class="py-2">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recurringInvoice->items as $item)
                    <tr class="border-b">
                        <td class="py-2">{{ $item->description }}</td>
                        <td class="py-2">${{ number_format($item->unit_price, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex items-center gap-3">
        @if($recurringInvoice->recurring_status === 'active')
            <form action="{{ route('recurring-invoices.pause', $recurringInvoice) }}" method="POST">
                @csrf
                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg">
                    Pause
                </button>
            </form>
        @endif

        @if($recurringInvoice->recurring_status === 'paused')
            <form action="{{ route('recurring-invoices.resume', $recurringInvoice) }}" method="POST">
                @csrf
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                    Resume
                </button>
            </form>
        @endif

        <a href="{{ route('recurring-invoices.edit', $recurringInvoice) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            Edit Template
        </a>

        <form action="{{ route('recurring-invoices.destroy', $recurringInvoice) }}" method="POST">
            @csrf
            @method('DELETE')
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                Delete Recurring
            </button>
        </form>
    </div>
@endsection

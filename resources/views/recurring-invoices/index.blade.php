@extends('layouts.app')
@section('title', 'Recurring Invoices')
@section('content')

    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800">Recurring Invoices</h1>
        <a href="{{ route('recurring-invoices.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            New Recurring Invoice
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-left text-gray-500">
                    <th class="px-4 py-3">Client</th>
                    <th class="px-4 py-3">Frequency</th>
                    <th class="px-4 py-3">Next Date</th>
                    <th class="px-4 py-3">Total</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recurringInvoices as $recurringInvoice)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('recurring-invoices.show', $recurringInvoice) }}" class="text-indigo-600 hover:text-indigo-800">
                                {{ $recurringInvoice->client->name }}
                            </a>
                        </td>
                        <td class="px-4 py-3">{{ ucfirst($recurringInvoice->recurring_frequency) }}</td>
                        <td class="px-4 py-3">{{ $recurringInvoice->next_recurring_date?->format('Y-m-d') }}</td>
                        <td class="px-4 py-3">${{ number_format($recurringInvoice->total, 2) }}</td>
                        <td class="px-4 py-3">{{ ucfirst($recurringInvoice->recurring_status) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection

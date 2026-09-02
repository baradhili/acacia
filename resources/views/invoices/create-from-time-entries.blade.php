@extends('layouts.app')
@section('title', 'New Invoice from Time Entries')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">New Invoice from Time Entries</h1>
        <p class="text-sm text-gray-500 mt-1">
            Select the approved, billable time entries to invoice. Entries across multiple clients must be invoiced separately.
        </p>
    </div>

    <!-- Client filter -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">All Clients</option>
                    @foreach ($clients as $id => $name)
                        <option value="{{ $id }}" {{ request('client_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
                Filter
            </button>
            <a href="{{ route('invoices.create-from-time-entries') }}" class="text-gray-600 hover:text-gray-800 px-4 py-2">Clear</a>
        </form>
    </div>

    @if ($selectedClient)
        <div class="mb-6 text-sm text-gray-600">
            Invoicing <span class="font-semibold text-gray-800">{{ $selectedClient->name }}</span>
        </div>
    @endif

    <form action="{{ route('invoices.create-from-time-entries.store') }}" method="POST" class="space-y-6">
        @csrf

        @include('invoices.partials.entry-picker', ['timeEntries' => $timeEntries])

        <div class="flex justify-end gap-4">
            <a href="{{ route('invoices.index') }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Create Invoice
            </button>
        </div>
    </form>

@endsection

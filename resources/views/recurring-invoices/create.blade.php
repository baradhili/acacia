@extends('layouts.app')
@section('title', 'Create Recurring Invoice')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Create Recurring Invoice</h1>
    </div>

    <form action="{{ route('recurring-invoices.store') }}" method="POST" class="space-y-6">
        @csrf

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Schedule</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                    <select name="client_id" id="client" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        @foreach($clients as $client)
                            <option value="{{ $client->id }}" @selected($clientId == $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Frequency *</label>
                    <select name="frequency" id="frequency" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        @foreach($frequencies as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Start Date *</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date', now()->toDateString()) }}" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Line Items</h2>

            <div id="itemsContainer">
                @php $items = old('items', [['description' => '', 'unit_price' => '']]); @endphp
                @foreach($items as $index => $item)
                    <div class="item-row grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
                        <div class="col-span-8">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                            <input type="text" name="items[{{ $index }}][description]"
                                value="{{ $item['description'] ?? '' }}" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                        </div>
                        <div class="col-span-4">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Amount</label>
                            <input type="number" name="items[{{ $index }}][unit_price]"
                                value="{{ $item['unit_price'] ?? 0 }}" step="0.01" min="0" required
                                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="button" id="addItemBtn" class="mt-4 bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm">
                + Add Line Item
            </button>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('recurring-invoices.index') }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Save Recurring
            </button>
        </div>
    </form>
@endsection

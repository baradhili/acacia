@extends('layouts.app')
@section('title', 'Edit Recurring Invoice')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Recurring Template</h1>
    </div>

    <form action="{{ route('recurring-invoices.update', $recurringInvoice) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Line Items</h2>

            @foreach($recurringInvoice->items as $index => $item)
                <div class="grid grid-cols-12 gap-2 mb-4 p-4 bg-gray-50 rounded-lg">
                    <div class="col-span-8">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" name="items[{{ $index }}][description]"
                            value="{{ old("items.{$index}.description", $item->description) }}" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                    </div>
                    <div class="col-span-4">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Amount</label>
                        <input type="number" name="items[{{ $index }}][unit_price]"
                            value="{{ old("items.{$index}.unit_price", $item->unit_price) }}" step="0.01" min="0" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full text-sm">
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('recurring-invoices.show', $recurringInvoice) }}" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Save
            </button>
        </div>
    </form>
@endsection

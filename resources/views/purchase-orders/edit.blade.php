@extends('layouts.app')
@section('title', 'Edit Purchase Order')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Purchase Order</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('purchase-orders.update', $purchaseOrder) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="client_id" class="block text-sm font-medium text-gray-700">Client *</label>
                    <select name="client_id" id="client_id" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Client</option>
                        @foreach($clients as $id => $name)
                            <option value="{{ $id }}" {{ $purchaseOrder->client_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Title *</label>
                    <input type="text" name="title" id="title" value="{{ old('title', $purchaseOrder->title) }}" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('title')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                    <textarea name="description" id="description" rows="2"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $purchaseOrder->description) }}</textarea>
                </div>

                <div>
                    <label for="budgeted_amount" class="block text-sm font-medium text-gray-700">Budgeted Amount ($) *</label>
                    <input type="number" name="budgeted_amount" id="budgeted_amount" value="{{ old('budgeted_amount', $purchaseOrder->budgeted_amount) }}" step="0.01" min="0" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('budgeted_amount')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $purchaseOrder->start_date?->format('Y-m-d')) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" name="end_date" id="end_date" value="{{ old('end_date', $purchaseOrder->end_date?->format('Y-m-d')) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('purchase-orders.show', $purchaseOrder) }}" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    Cancel
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Update Purchase Order
                </button>
            </div>
        </form>
    </div>

    <x-document-upload :model="$purchaseOrder" />
@endsection
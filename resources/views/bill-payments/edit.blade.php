@extends('layouts.app')
@section('title', 'Edit Payment ' . $billPayment->payment_number)
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Supplier Payment {{ $billPayment->payment_number }}</h1>
    </div>

    <form action="{{ route('bill-payments.update', $billPayment) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Payment Details</h2>

            @if ($billPayment->allocations->isNotEmpty())
                <div class="mb-4 p-3 bg-yellow-50 rounded-lg text-sm text-yellow-800">
                    This payment has {{ $billPayment->allocations->count() }} bill allocation(s) totalling
                    ${{ number_format($billPayment->allocated_amount, 2) }}. The amount cannot be reduced below the
                    allocated total and the supplier cannot be changed while allocations exist.
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier *</label>
                    <select name="supplier_id" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Supplier</option>
                        @foreach ($suppliers as $id => $name)
                            <option value="{{ $id }}"
                                {{ old('supplier_id', $billPayment->supplier_id) == $id ? 'selected' : '' }}>{{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('supplier_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                        <input type="number" name="amount" value="{{ old('amount', $billPayment->amount) }}"
                            step="0.01" min="0.01" required
                            class="pl-7 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    </div>
                    @error('amount')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                    <input type="date" name="payment_date" value="{{ old('payment_date', $billPayment->payment_date->format('Y-m-d')) }}"
                        required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    @error('payment_date')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                    <select name="payment_method" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        @foreach ($paymentMethods as $value => $label)
                            <option value="{{ $value }}"
                                {{ old('payment_method', $billPayment->payment_method) == $value ? 'selected' : '' }}>
                                {{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                    <input type="text" name="reference" value="{{ old('reference', $billPayment->reference) }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes', $billPayment->notes) }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-4">
            <a href="{{ route('bill-payments.show', $billPayment) }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Update Payment
            </button>
        </div>
    </form>

@endsection

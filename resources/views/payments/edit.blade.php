@extends('layouts.app')
@section('title', 'Edit Payment ' . $payment->payment_number)
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Payment {{ $payment->payment_number }}</h1>
    </div>

    @if (session('error'))
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <ul class="list-disc list-inside text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <p class="text-sm text-blue-800">
            <strong>Managing invoice allocations:</strong> allocations can't be edited on this page —
            use the <a href="{{ route('payments.show', $payment) }}" class="underline font-medium">Allocate to Invoice</a>
            action on the payment view page to add allocations, or the Remove buttons there to un-allocate.
            To change the client of a payment with allocations, remove them all first.
        </p>
    </div>

    <form action="{{ route('payments.update', $payment) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Payment Details</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Client *</label>
                    <select name="client_id" id="clientSelect" required
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                        <option value="">Select Client</option>
                        @foreach ($clients as $id => $name)
                            <option value="{{ $id }}"
                                {{ old('client_id', $payment->client_id) == $id ? 'selected' : '' }}>{{ $name }}
                            </option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">$</span>
                        <input type="number" name="amount" value="{{ old('amount', $payment->amount) }}" step="0.01" min="0.01"
                            required
                            class="pl-7 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                    </div>
                    @error('amount')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Payment Date *</label>
                    <input type="date" name="payment_date" value="{{ old('payment_date', $payment->payment_date->toDateString()) }}"
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
                            <option value="{{ $value }}" {{ old('payment_method', $payment->payment_method) == $value ? 'selected' : '' }}>
                                {{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                    <input type="text" name="reference" value="{{ old('reference', $payment->reference) }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full"
                        placeholder="Transaction ID, Cheque #, etc.">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes', $payment->notes) }}"
                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 w-full">
                </div>
            </div>
        </div>

        @if ($payment->allocations->isNotEmpty())
            <div class="bg-yellow-50 rounded-lg shadow p-6 border border-yellow-200">
                <h2 class="text-lg font-semibold text-yellow-800 mb-4">⚠️ Existing Allocations</h2>
                <p class="text-sm text-yellow-700 mb-4">
                    This payment has existing invoice allocations. Editing the amount may cause allocation discrepancies.
                </p>
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-yellow-200">
                            <th class="text-left py-2 text-xs font-medium text-yellow-700 uppercase">Invoice</th>
                            <th class="text-right py-2 text-xs font-medium text-yellow-700 uppercase">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($payment->allocations as $allocation)
                            <tr>
                                <td class="py-2">{{ $allocation->invoice->invoice_number }}</td>
                                <td class="py-2 text-right">${{ number_format($allocation->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="flex justify-end gap-4">
            <a href="{{ route('payments.show', $payment) }}"
                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-6 py-2 rounded-lg">
                Cancel
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                Update Payment
            </button>
        </div>
    </form>

    <x-document-upload :model="$payment" />
@endsection

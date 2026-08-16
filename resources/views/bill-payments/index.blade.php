@extends('layouts.app')
@section('title', 'Supplier Payments')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Supplier Payments</h1>
        <a href="{{ route('bill-payments.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + Record Payment
        </a>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                <select name="supplier_id" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">All Suppliers</option>
                    @foreach($suppliers as $id => $name)
                        <option value="{{ $id }}" {{ request('supplier_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
                <input type="date" name="start_date" value="{{ request('start_date') }}"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
                <input type="date" name="end_date" value="{{ request('end_date') }}"
                    class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
                Filter
            </button>
            <a href="{{ route('bill-payments.index') }}" class="text-gray-600 hover:text-gray-800 px-4 py-2">Clear</a>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Allocated</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($payments as $payment)
                    <tr>
                        <td class="px-6 py-4">
                            <a href="{{ route('bill-payments.show', $payment) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                                {{ $payment->payment_number }}
                            </a>
                        </td>
                        <td class="px-6 py-4 text-gray-900">{{ $payment->supplier->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ $payment->payment_date->format('d M Y') }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ $payment->formatted_method }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($payment->amount, 2) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">
                            ${{ number_format($payment->allocated_amount, 2) }}
                            @if($payment->unallocated_amount > 0)
                                <span class="text-yellow-600 text-xs">(${{ number_format($payment->unallocated_amount, 2) }} free)</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-gray-900">{{ Str::limit($payment->reference ?? '-', 20) }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('bill-payments.show', $payment) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                            <a href="{{ route('bill-payments.edit', $payment) }}" class="text-gray-600 hover:text-gray-900">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-4 text-center text-gray-500">No supplier payments found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $payments->links() }}
    </div>

@endsection

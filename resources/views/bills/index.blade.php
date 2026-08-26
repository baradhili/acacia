@extends('layouts.app')
@section('title', 'Bills')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Bills</h1>
        <a href="{{ route('bills.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + New Bill
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">All Statuses</option>
                    <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="open" {{ request('status') == 'open' ? 'selected' : '' }}>Open</option>
                    <option value="partially_paid" {{ request('status') == 'partially_paid' ? 'selected' : '' }}>Partially Paid</option>
                    <option value="paid" {{ request('status') == 'paid' ? 'selected' : '' }}>Paid</option>
                    <option value="overdue" {{ request('status') == 'overdue' ? 'selected' : '' }}>Overdue</option>
                    <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>
            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
                Filter
            </button>
            <a href="{{ route('bills.index') }}" class="text-gray-600 hover:text-gray-800 px-4 py-2">Clear</a>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bill #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Supplier</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Due</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bill Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Due Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($bills as $bill)
                    <tr>
                        <td class="px-6 py-4">
                            <a href="{{ route('bills.show', $bill) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                                {{ $bill->bill_number }}
                            </a>
                            <x-document-icon :count="$bill->documents_count" />
                        </td>
                        <td class="px-6 py-4 text-gray-900">{{ $bill->supplier->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ Str::limit($bill->reference ?? '-', 20) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($bill->total, 2) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($bill->amount_paid, 2) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900 {{ $bill->amount_due > 0 ? 'text-red-600 font-medium' : 'text-green-600' }}">
                            ${{ number_format($bill->amount_due, 2) }}
                        </td>
                        <td class="px-6 py-4 text-gray-900">
                            {{ $bill->bill_date->format('d M Y') }}
                        </td>
                        <td class="px-6 py-4 text-gray-900 {{ $bill->is_overdue ? 'text-red-600 font-medium' : '' }}">
                            {{ $bill->due_date?->format('d M Y') }}
                            @if($bill->is_overdue)
                                <span class="text-xs">({{ abs($bill->days_until_due) }} days overdue)</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @php
                                $statusColors = [
                                    'draft' => 'bg-gray-100 text-gray-800',
                                    'open' => 'bg-blue-100 text-blue-800',
                                    'partially_paid' => 'bg-yellow-100 text-yellow-800',
                                    'paid' => 'bg-green-100 text-green-800',
                                    'overdue' => 'bg-red-100 text-red-800',
                                    'cancelled' => 'bg-gray-100 text-gray-500',
                                ];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusColors[$bill->status] }}">
                                {{ ucfirst(str_replace('_', ' ', $bill->status)) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('bills.show', $bill) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                            @if($bill->status === 'draft')
                                <a href="{{ route('bills.edit', $bill) }}" class="text-gray-600 hover:text-gray-900 mr-3">Edit</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-6 py-4 text-center text-gray-500">No bills found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $bills->links() }}
    </div>

@endsection

@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Purchase Orders</h1>
        <a href="{{ route('purchase-orders.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + New Purchase Order
        </a>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO Number</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Budget</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Used</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Utilization</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Start Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($purchaseOrders as $po)
                    <tr>
                        <td class="px-6 py-4">
                            <a href="{{ route('purchase-orders.show', $po) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                                {{ $po->po_number }}
                            </a>
                            <x-document-icon :count="$po->documents_count" />
                        </td>
                        <td class="px-6 py-4 text-gray-900">{{ $po->client->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ Str::limit($po->title, 30) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($po->budgeted_amount, 2) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($po->used_amount, 2) }}</td>
                        <td class="px-6 py-4 text-right">
                            <span class="text-gray-900">{{ number_format($po->utilization, 1) }}%</span>
                        </td>
                        <td class="px-6 py-4 text-gray-900">
                            @if($po->start_date)
                                {{ $po->start_date->format('d M Y') }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @php
                                $statusColors = [
                                    'draft' => 'bg-gray-100 text-gray-800',
                                    'open' => 'bg-blue-100 text-blue-800',
                                    'partially_used' => 'bg-yellow-100 text-yellow-800',
                                    'completed' => 'bg-green-100 text-green-800',
                                    'cancelled' => 'bg-red-100 text-red-800',
                                ];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusColors[$po->status] }}">
                                {{ ucfirst(str_replace('_', ' ', $po->status)) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('purchase-orders.show', $po) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                            @if($po->status === 'draft')
                                <a href="{{ route('purchase-orders.edit', $po) }}" class="text-gray-600 hover:text-gray-900 mr-3">Edit</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-6 py-4 text-center text-gray-500">No purchase orders found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $purchaseOrders->links() }}
    </div>

@endsection
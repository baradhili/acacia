@extends('layouts.app')
@section('title', 'Estimate ' . $estimate->estimate_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Estimate {{ $estimate->estimate_number }}</h1>
            <p class="text-gray-600">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                    @if($estimate->status === 'accepted' || $estimate->status === 'converted') bg-green-100 text-green-800
                    @elseif($estimate->status === 'rejected') bg-red-100 text-red-800
                    @elseif($estimate->status === 'expired') bg-orange-100 text-orange-800
                    @else bg-blue-100 text-blue-800 @endif">
                    {{ ucfirst($estimate->status) }}
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            @if($estimate->status === 'draft')
                <form action="{{ route('estimates.send', $estimate) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Send Estimate</button>
                </form>
                <a href="{{ route('estimates.edit', $estimate) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">Edit</a>
            @endif
            @if($estimate->status === 'sent')
                <form action="{{ route('estimates.accept', $estimate) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">Accept</button>
                </form>
                <form action="{{ route('estimates.reject', $estimate) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">Reject</button>
                </form>
            @endif
            @if($estimate->status === 'accepted')
                <form action="{{ route('estimates.convertToInvoice', $estimate) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Convert to Invoice
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Items</h2>
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2 text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                            <th class="text-right py-2 text-xs font-medium text-gray-500 uppercase">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($estimate->items as $item)
                            <tr>
                                <td class="py-3">{{ $item->description }}</td>
                                <td class="py-3 text-right">{{ number_format($item->quantity, 2) }}</td>
                                <td class="py-3 text-right">${{ number_format($item->unit_price, 2) }}</td>
                                <td class="py-3 text-right font-medium">${{ number_format($item->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-4 flex justify-end">
                    <div class="text-right">
                        <p class="text-sm text-gray-600">Subtotal</p>
                        <p class="">${{ number_format($estimate->subtotal, 2) }}</p>
                        <p class="text-sm text-gray-600 mt-2">Tax</p>
                        <p class="">${{ number_format($estimate->tax_amount, 2) }}</p>
                        @if($estimate->discount_amount > 0)
                            <p class="text-sm text-gray-600 mt-2">Discount</p>
                            <p class="">-${{ number_format($estimate->discount_amount, 2) }}</p>
                        @endif
                        <p class="text-lg font-bold mt-2">Total</p>
                        <p class="text-lg font-bold">${{ number_format($estimate->total, 2) }}</p>
                    </div>
                </div>
            </div>

            @if($estimate->notes)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Notes</h2>
                    <p class="text-gray-600">{{ $estimate->notes }}</p>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Details</h2>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm text-gray-500">Total</dt>
                        <dd class="text-lg font-bold">${{ number_format($estimate->total, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Issue Date</dt>
                        <dd class="font-medium">{{ $estimate->issue_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Valid Until</dt>
                        <dd class="font-medium {{ $estimate->is_expired ? 'text-red-600' : '' }}">
                            {{ $estimate->valid_until->format('d M Y') }}
                        </dd>
                    </div>
                    @if($estimate->converted_to_invoice_id)
                        <div class="pt-3 border-t">
                            <dt class="text-sm text-gray-500">Converted to Invoice</dt>
                            <dd>
                                <a href="{{ route('invoices.show', $estimate->converted_to_invoice_id) }}" class="text-indigo-600 hover:text-indigo-800">
                                    {{ $estimate->convertedToInvoice->invoice_number ?? 'Invoice' }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Client</h2>
                <p class="font-medium">{{ $estimate->client->name }}</p>
                <a href="{{ route('clients.show', $estimate->client) }}" class="text-indigo-600 hover:text-indigo-800 text-sm mt-2 inline-block">
                    View Client →
                </a>
            </div>
        </div>
    </div>

@endsection

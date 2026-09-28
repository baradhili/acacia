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
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Send</button>
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
                <form action="{{ route('estimates.convertToInvoice', $estimate) }}" method="POST" class="inline-flex items-center gap-3">
                    @csrf
                    @if($estimate->hasOptionalItems())
                        <label class="flex items-center gap-2 text-sm text-gray-700"
                            title="Optional lines are excluded unless ticked here">
                            <input type="checkbox" name="include_optional" value="1"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Include optional items
                        </label>
                    @endif
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
                @php
                    // Consecutive lines sharing a section label group under
                    // one heading; unlabeled lines stand alone.
                    $sections = [];
                    $current = null;
                    foreach ($estimate->items as $item) {
                        $label = $item->section ?: null;
                        if ($label === null || $label !== $current) {
                            $sections[] = ['label' => $label, 'items' => []];
                            $current = $label;
                        }
                        $sections[count($sections) - 1]['items'][] = $item;
                    }
                @endphp
                @foreach($sections as $section)
                    @if($section['label'])
                        <p class="px-2 py-1 mt-4 first:mt-0 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 rounded">
                            {{ $section['label'] }}
                        </p>
                    @endif
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
                            @foreach($section['items'] as $item)
                                <tr class="{{ $item->is_optional ? 'text-gray-500' : '' }}">
                                    <td class="py-3">
                                        {{ $item->description }}
                                        @if($item->service)
                                            <span class="block text-xs text-gray-400">{{ $item->service->name }}</span>
                                        @endif
                                        @if($item->is_optional)
                                            <span class="ml-2 px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800">optional</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-right">{{ number_format($item->quantity, 2) }}</td>
                                    <td class="py-3 text-right">${{ number_format($item->unit_price, 2) }}</td>
                                    <td class="py-3 text-right font-medium">${{ number_format($item->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
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
                        @if($estimate->optional_total > 0)
                            <p class="text-sm text-amber-700 mt-2">Optional extras</p>
                            <p class="text-amber-700">${{ number_format($estimate->optional_total, 2) }}</p>
                        @endif
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

@extends('layouts.app')
@section('title', 'Credit Note ' . $creditNote->credit_note_number)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Credit Note {{ $creditNote->credit_note_number }}</h1>
            <p class="text-gray-600">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                    @if($creditNote->status === 'applied') bg-green-100 text-green-800
                    @elseif($creditNote->status === 'void') bg-gray-100 text-gray-500
                    @else bg-blue-100 text-blue-800 @endif">
                    {{ ucfirst($creditNote->status) }}
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            @if($creditNote->status === 'issued' && $creditNote->remaining_amount > 0)
                <form action="{{ route('credit-notes.void', $creditNote) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg"
                        onclick="return confirm('Void this credit note?');">Void</button>
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
                        @foreach($creditNote->items as $item)
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
                        <p class="text-sm text-gray-600">Total</p>
                        <p class="text-xl font-bold">${{ number_format($creditNote->total, 2) }}</p>
                    </div>
                </div>
            </div>

            @if($creditNote->reason)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Reason</h2>
                    <p class="text-gray-600">{{ $creditNote->reason }}</p>
                </div>
            @endif

            @if($creditNote->notes)
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-2">Notes</h2>
                    <p class="text-gray-600">{{ $creditNote->notes }}</p>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Summary</h2>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm text-gray-500">Total</dt>
                        <dd class="text-lg font-bold">${{ number_format($creditNote->total, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Applied</dt>
                        <dd class="font-medium">${{ number_format($creditNote->applied_amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500">Remaining</dt>
                        <dd class="font-medium text-green-600">${{ number_format($creditNote->remaining_amount, 2) }}</dd>
                    </div>
                    <div class="pt-3 border-t">
                        <dt class="text-sm text-gray-500">Issue Date</dt>
                        <dd class="font-medium">{{ $creditNote->issue_date->format('d M Y') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Client</h2>
                <p class="font-medium">{{ $creditNote->client->name }}</p>
                <a href="{{ route('clients.show', $creditNote->client) }}" class="text-indigo-600 hover:text-indigo-800 text-sm mt-2 inline-block">
                    View Client →
                </a>
            </div>
        </div>
    </div>

@endsection

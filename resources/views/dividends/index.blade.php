@extends('layouts.app')
@section('title', 'Dividend Declarations')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Dividend Declarations</h1>
            <p class="text-sm text-gray-500 mt-1">
                Declaration → calculation → approval (posts Dr Dividends Paid / Cr Dividends Payable) →
                manual payment run → record payment (posts Dr Dividends Payable / Cr Bank, emails statements).
            </p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('franking-account.index') }}" class="text-sm text-indigo-600 hover:underline self-center">Franking account</a>
            <a href="{{ route('dividends.create') }}"
                class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                + Declaration
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Declaration</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Declared</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Per share</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Franking</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cash total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit attached</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payment date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($declarations as $declaration)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('dividends.show', $declaration) }}" class="text-indigo-600 hover:underline font-medium">
                                    {{ $declaration->declaration_number }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-sm">{{ $declaration->declaration_date->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-sm">{{ $declaration->shareClass?->code }}</td>
                            <td class="px-4 py-3 text-sm">{{ \App\Models\DividendDeclaration::dividendTypes()[$declaration->dividend_type] ?? '' }}</td>
                            <td class="px-4 py-3 text-sm text-right">${{ number_format((float) $declaration->amount_per_share, 4) }}</td>
                            <td class="px-4 py-3 text-sm text-right">{{ number_format((float) $declaration->franking_percentage, 0) }}%</td>
                            <td class="px-4 py-3 text-sm text-right">${{ number_format((float) $declaration->total_cash_dividend, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right">${{ number_format((float) $declaration->total_franking_credit, 2) }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $badge = match($declaration->status) {
                                        'draft' => 'bg-gray-100 text-gray-700',
                                        'approved' => 'bg-blue-100 text-blue-700',
                                        'completed' => 'bg-green-100 text-green-800',
                                        default => 'bg-red-100 text-red-700',
                                    };
                                @endphp
                                <span class="px-2 py-1 text-xs font-medium rounded-full {{ $badge }}">{{ $declaration->statusLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm">{{ $declaration->payment_date->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-gray-500">No dividend declarations yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-gray-200">
            {{ $declarations->links() }}
        </div>
    </div>
@endsection

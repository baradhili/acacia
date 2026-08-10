@extends('layouts.app')

@section('title', 'Journal Entries')

@section('header')
    <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold text-gray-800">Journal Entries</h2>
        <a href="{{ route('journal-entries.create') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
            + New Journal Entry
        </a>
    </div>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($entries as $entry)
                    @php
                        $debit = $entry->lineItems->firstWhere('credited', false);
                        $credit = $entry->lineItems->firstWhere('credited', true);
                    @endphp
                    <tr>
                        <td class="px-6 py-4 text-gray-900">{{ $entry->transaction_date->format('d M Y') }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ $entry->narration }}</td>
                        <td class="px-6 py-4 text-gray-900">
                            {{ $debit?->account?->name }} / {{ $credit?->account?->name }}
                        </td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ $debit ? number_format($debit->amount, 2) : '-' }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ $credit ? number_format($credit->amount, 2) : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">No journal entries found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

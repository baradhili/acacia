@extends('layouts.app')
@section('title', 'Shareholders')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Shareholders</h1>
            <p class="text-sm text-gray-500 mt-1">
                Holdings come from the shareholding transaction ledger; master data and bank details are
                maintained on the
                <a href="{{ route('company-profile.index') }}" class="text-indigo-600 hover:underline">company profile</a>.
            </p>
        </div>
        <a href="{{ route('dividends.index') }}"
            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Dividend Declarations
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    @unless($profile)
        <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
            No company profile exists yet — create one on the
            <a href="{{ route('company-profile.index') }}" class="text-indigo-600 hover:underline">company profile</a> screen.
        </div>
    @else
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shareholder</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Holdings</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bank details</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($shareholders as $shareholder)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ $shareholder->name }}</div>
                                    @if($shareholder->abn)
                                        <div class="text-xs text-gray-500">ABN {{ $shareholder->abn }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">
                                    <div>{{ $shareholder->email ?: '—' }}</div>
                                    <div class="text-xs text-gray-400">{{ $shareholder->phone ?: '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    @forelse($shareholder->holdings as $holding)
                                        @php
                                            $issued = $issuedTotals[$holding['class']->id] ?? 0;
                                            $pct = $issued > 0 ? round($holding['quantity'] / $issued * 100, 2) : 0;
                                        @endphp
                                        <div>
                                            <span class="font-medium text-gray-900">{{ number_format($holding['quantity']) }}</span>
                                            <span class="text-gray-500">{{ $holding['class']->code }}</span>
                                            <span class="text-xs text-gray-400">({{ $pct }}% of {{ number_format($issued) }})</span>
                                        </div>
                                    @empty
                                        <span class="text-gray-400">No holdings</span>
                                    @endforelse
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    @if($shareholder->bankDetailsComplete())
                                        <span class="text-green-600">Complete</span>
                                    @else
                                        <span class="text-amber-600">Incomplete</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full
                                        {{ $shareholder->status === \App\Models\CompanyShareholder::STATUS_ACTIVE ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $shareholder->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('shareholders.show', $shareholder) }}"
                                        class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    No shareholders recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endunless
@endsection

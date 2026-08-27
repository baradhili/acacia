@extends('layouts.app')
@section('title', 'Domain Names')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Domain Names</h1>
        <a href="{{ route('domains.create') }}"
            class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">Add domain</a>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Domain</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registrar</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purchased</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Renewal due</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cost (ex GST)</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Life</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($domains as $domain)
                        <tr class="hover:bg-gray-50 {{ $domain->status === \App\Models\Domain::STATUS_RETIRED ? 'opacity-50' : '' }}">
                            <td class="px-4 py-3">
                                <a href="{{ route('domains.show', $domain) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                                    {{ $domain->name }}
                                </a>
                                <span class="block text-xs text-gray-400">{{ $domain->account?->code }} {{ $domain->account?->name }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $domain->registrar ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ optional($domain->purchased_at)->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if ($domain->expiry_date)
                                    <span class="{{ $domain->isExpired() ? 'text-red-600 font-medium' : ($domain->isExpiringSoon() ? 'text-amber-600 font-medium' : 'text-gray-600') }}">
                                        {{ $domain->expiry_date->format('d/m/Y') }}
                                        @if ($domain->isExpired())
                                            (expired)
                                        @elseif ($domain->isExpiringSoon())
                                            ({{ $domain->daysUntilExpiry() }} days)
                                        @endif
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($domain->cost, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ $domain->indefinite_life ? 'Indefinite (no amortisation)' : $domain->useful_life_months . ' months' }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($domain->status === \App\Models\Domain::STATUS_ACTIVE)
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">Active</span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-500">Retired</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-sm text-gray-500">
                                No domains registered. Capitalise initial purchases on a bill (category 170 Domain Names), then add the registry entry here.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        Initial purchases capitalise to {{ config('subscriptions.domain_intangible_code') }} (intangible asset, BAS G10);
        renewals are expensed immediately to {{ config('subscriptions.domain_renewal_expense_code') }} and never increase the carrying amount.
    </p>
@endsection

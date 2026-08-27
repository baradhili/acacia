@extends('layouts.app')
@section('title', $domain->name)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $domain->name }}</h1>
            <p class="text-sm text-gray-500">
                {{ $domain->registrar ?? 'No registrar recorded' }} ·
                {{ $domain->account?->code ? $domain->account->code . ' ' . $domain->account->name : 'not capitalised on a tracked account' }} ·
                {{ $domain->indefinite_life ? 'Indefinite life' : $domain->useful_life_months . '-month life' }}
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('domains.edit', $domain) }}"
                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-sm">Edit</a>
            @if ($domain->isActive())
                <form method="POST" action="{{ route('domains.destroy', $domain) }}"
                    onsubmit="return confirm('Retire {{ $domain->name }} from the registry? The ledger is untouched.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm">Retire</button>
                </form>
            @endif
        </div>
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

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Capitalised cost</p>
            <p class="text-xl font-bold text-gray-800 mt-1">${{ number_format($domain->cost, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Purchased</p>
            <p class="text-xl font-bold text-gray-800 mt-1">{{ optional($domain->purchased_at)->format('d/m/Y') ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Renewal due</p>
            <p class="text-xl font-bold {{ $domain->isExpired() ? 'text-red-600' : ($domain->isExpiringSoon() ? 'text-amber-600' : 'text-gray-800') }} mt-1">
                {{ optional($domain->expiry_date)->format('d/m/Y') ?? '—' }}
            </p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Amortisation</p>
            <p class="text-xl font-bold text-gray-800 mt-1">
                {{ $domain->prepayments->where('status', '!=', \App\Models\Prepayment::STATUS_VOID)->count() ? 'Scheduled' : ($domain->indefinite_life ? 'None' : 'Not created') }}
            </p>
        </div>
    </div>

    @if ($domain->notes)
        <div class="bg-white rounded-lg shadow p-4 mb-6 text-sm text-gray-600">{{ $domain->notes }}</div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-md font-semibold text-gray-800 mb-3">Accounting treatment</h2>
        <ul class="text-sm text-gray-600 space-y-2 list-disc list-inside">
            <li>
                <span class="font-medium text-gray-800">Initial purchase</span> — capitalised to the intangible asset
                ({{ config('subscriptions.domain_intangible_code') }}): record it on a bill with the
                "Capital purchases" category{{ $domain->indefinite_life ? ' and leave it unamortised (indefinite life)' : '' }}.
                @if (!$domain->indefinite_life)
                    <form method="POST" action="{{ route('domains.amortisation', $domain) }}" class="inline">
                        @csrf
                        <button type="submit" class="ml-2 text-indigo-600 hover:text-indigo-800 underline">
                            Create amortisation schedule ({{ $domain->useful_life_months }} months →
                            {{ config('subscriptions.amortisation_expense_code') }})
                        </button>
                    </form>
                @endif
            </li>
            <li>
                <span class="font-medium text-red-700">Renewals are never capitalised</span> — they do not enhance the
                asset. Expense them to {{ config('subscriptions.domain_renewal_expense_code') }} Domain Renewal Expense:
                <a href="{{ route('bills.create') }}" class="ml-1 text-indigo-600 hover:text-indigo-800 underline">record a renewal bill</a>
                with the description "Domain renewal: {{ $domain->name }}" and the Expenses category
                {{ config('subscriptions.domain_renewal_expense_code') }}.
            </li>
        </ul>
    </div>

    @if ($domain->prepayments->isNotEmpty())
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-md font-semibold text-gray-800 mb-3">Amortisation schedules</h2>
            <ul class="text-sm space-y-1">
                @foreach ($domain->prepayments as $prepayment)
                    <li>
                        <a href="{{ route('prepayments.show', $prepayment) }}" class="text-indigo-600 hover:text-indigo-800">
                            {{ $prepayment->description }}
                        </a>
                        — ${{ number_format($prepayment->total_amount, 2) }} over {{ $prepayment->periods }} months,
                        {{ ucfirst($prepayment->status) }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection

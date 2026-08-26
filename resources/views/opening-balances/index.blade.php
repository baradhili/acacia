@extends('layouts.app')
@section('title', 'Opening Balances')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Opening Balances</h1>
        @if ($period)
            <p class="text-sm text-gray-500">
                Effective date: {{ $openingDate->format('d M Y') }} — the day before the fiscal year starts
            </p>
        @endif
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

    <!-- Fiscal year selector -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" action="{{ route('opening-balances.index') }}" class="flex items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Fiscal Year</label>
                <select name="year" onchange="this.form.submit()"
                        class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @forelse($periods as $p)
                        <option value="{{ $p->calendar_year }}" {{ $period?->is($p) ? 'selected' : '' }}>
                            FY{{ $p->calendar_year }}
                        </option>
                    @empty
                        <option value="">No reporting periods</option>
                    @endforelse
                </select>
            </div>
            <noscript>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Show</button>
            </noscript>
        </form>
    </div>

    @if ($period)
        <form method="POST" action="{{ route('opening-balances.store') }}">
            @csrf
            <input type="hidden" name="year" value="{{ $period->calendar_year }}">

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @php
                                $debitTotal = 0;
                                $creditTotal = 0;
                            @endphp
                            @foreach ($accounts as $account)
                                @php
                                    $current = $existing[$account->id] ?? null;
                                    $debit = $current && $current['side'] === 'D' ? $current['amount'] : null;
                                    $credit = $current && $current['side'] === 'C' ? $current['amount'] : null;
                                    $debitTotal += (float) old('balances.'.$account->id.'.debit', $debit ?? 0);
                                    $creditTotal += (float) old('balances.'.$account->id.'.credit', $credit ?? 0);
                                @endphp
                                <tr>
                                    <td class="px-6 py-2 text-gray-900">{{ $account->code }}</td>
                                    <td class="px-6 py-2 text-gray-900">{{ $account->name }}</td>
                                    <td class="px-6 py-2 text-gray-500">{{ config('ifrs.accounts')[$account->account_type] ?? $account->account_type }}</td>
                                    <td class="px-6 py-2">
                                        <input type="number" step="0.01" min="0"
                                               name="balances[{{ $account->id }}][debit]"
                                               value="{{ old('balances.'.$account->id.'.debit', $debit) }}"
                                               class="w-full max-w-[10rem] ml-auto rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-right">
                                    </td>
                                    <td class="px-6 py-2">
                                        <input type="number" step="0.01" min="0"
                                               name="balances[{{ $account->id }}][credit]"
                                               value="{{ old('balances.'.$account->id.'.credit', $credit) }}"
                                               class="w-full max-w-[10rem] ml-auto rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-right">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="3" class="px-6 py-3 text-right font-bold text-gray-900">Totals</td>
                                <td class="px-6 py-3 text-right font-bold text-gray-900">${{ number_format($debitTotal, 2) }}</td>
                                <td class="px-6 py-3 text-right font-bold text-gray-900">${{ number_format($creditTotal, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @if (abs($debitTotal - $creditTotal) >= 0.005)
                <p class="mt-3 text-sm text-amber-600">
                    Debits and credits are out by ${{ number_format(abs($debitTotal - $creditTotal), 2) }} —
                    balances can be saved unbalanced, but the difference should end up in an equity account.
                </p>
            @endif

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('opening-balances.index', ['year' => $period->calendar_year]) }}" class="px-4 py-2 text-gray-700 hover:text-gray-900">Cancel</a>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">Save Opening Balances</button>
            </div>
        </form>
    @else
        <div class="bg-white rounded-lg shadow p-6 text-gray-500">
            No reporting periods exist yet — run the IFRS seeder first.
        </div>
    @endif
@endsection

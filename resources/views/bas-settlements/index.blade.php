@extends('layouts.app')
@section('title', 'BAS Settlements')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">BAS Settlements</h1>
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
    @if ($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 max-w-4xl mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Unsettled positions</h2>
        <p class="text-sm text-gray-500 mb-4">
            Everything never settled to date — the balances of the settlement accounts, across any
            number of quarters (and even closed financial years), because clearing them is only
            ever recorded here. GST nets GST Payable against GST Receivable; PAYG withholding and
            income tax settle their single liability account (a debit balance is an overpayment
            refunded by the ATO). Claiming late simply means settling at a later date.
        </p>

        <form method="GET" class="flex items-end gap-3 mb-4">
            <div>
                <label for="as_at" class="block text-sm font-medium text-gray-700 mb-1">Position as at</label>
                <input type="date" name="as_at" id="as_at" value="{{ $positionAsAt }}"
                    class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <button type="submit"
                class="px-4 py-2 bg-slate-600 text-white text-sm font-medium rounded-md hover:bg-slate-700 shrink-0">
                Recalculate
            </button>
        </form>

        @if ($quarterEnds !== [])
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach (array_slice(array_reverse($quarterEnds), 0, 4) as $quarter)
                    <a href="{{ route('bas-settlements.index', ['as_at' => $quarter['end']->toDateString()]) }}"
                        class="px-3 py-1 text-xs rounded-full border border-indigo-200 text-indigo-700 hover:bg-indigo-50">
                        {{ $quarter['label'] }}
                    </a>
                @endforeach
            </div>
        @endif

        <table class="w-full text-sm border-y border-gray-100">
            <thead>
                <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                    <th class="py-2 pr-4">Type</th>
                    <th class="py-2 pr-4 text-right">Payable (collected)</th>
                    <th class="py-2 pr-4 text-right">Receivable (credits)</th>
                    <th class="py-2 text-right">Net</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach (\App\Models\BasSettlement::TYPES as $type)
                    @php($position = $positions[$type])
                    <tr>
                        <td class="py-2 pr-4 font-medium text-gray-700">{{ \App\Models\BasSettlement::typeLabel($type) }}</td>
                        <td class="py-2 pr-4 text-right">${{ number_format($position['payable'], 2) }}</td>
                        <td class="py-2 pr-4 text-right">${{ number_format($position['receivable'], 2) }}</td>
                        <td class="py-2 text-right font-bold {{ $position['net'] >= 0 ? 'text-red-700' : 'text-green-700' }}">
                            ${{ number_format(abs($position['net']), 2) }}
                            <span class="text-xs font-medium">
                                {{ $position['net'] > 0 ? 'payable to ATO' : ($position['net'] < 0 ? 'refundable from ATO' : '— nothing to settle') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-4xl mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Record BAS settlement</h2>
        <p class="text-sm text-gray-500 mb-4">
            Posts one journal that nets the chosen accounts and moves the difference to/from the
            bank: <code>Dr Payable / Cr Receivable / Cr Bank</code> when paying the ATO, mirrored
            for a refund. A BAS is lodged and paid in the month after its quarter, so the bank
            date normally lags the period covered.
        </p>

        <form method="POST" action="{{ route('bas-settlements.store') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            @csrf

            <div>
                <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="type" id="type"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach (\App\Models\BasSettlement::TYPES as $type)
                        <option value="{{ $type }}" {{ old('type', \App\Models\BasSettlement::TYPE_GST) === $type ? 'selected' : '' }}>
                            {{ \App\Models\BasSettlement::typeLabel($type) }}
                        </option>
                    @endforeach
                </select>
                @error('type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="settle_as_at" class="block text-sm font-medium text-gray-700 mb-1">Covers GST to</label>
                <input type="date" name="as_at" id="settle_as_at" value="{{ old('as_at', $defaultAsAt) }}" required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('as_at') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="settled_at" class="block text-sm font-medium text-gray-700 mb-1">Bank date</label>
                <input type="date" name="settled_at" id="settled_at" value="{{ old('settled_at', now()->toDateString()) }}" required
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('settled_at') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="reference" class="block text-sm font-medium text-gray-700 mb-1">Reference (optional)</label>
                <input type="text" name="reference" id="reference" value="{{ old('reference') }}" maxlength="255"
                    placeholder="e.g. ATO receipt / bank statement ref"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('reference') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-end">
                <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                    Record Settlement
                </button>
            </div>

            <div class="md:col-span-5">
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea name="notes" id="notes" rows="2"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes') }}</textarea>
                @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-4xl">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Past settlements</h2>

        @if ($settlements->isEmpty())
            <p class="text-sm text-gray-500">No settlements recorded yet.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                        <th class="py-2 pr-4">Type</th>
                        <th class="py-2 pr-4">Covers to</th>
                        <th class="py-2 pr-4">Bank date</th>
                        <th class="py-2 pr-4 text-right">Payable</th>
                        <th class="py-2 pr-4 text-right">Receivable</th>
                        <th class="py-2 pr-4 text-right">Net</th>
                        <th class="py-2 pr-4">Direction</th>
                        <th class="py-2 pr-4">Reference</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($settlements as $settlement)
                        <tr class="{{ $settlement->isReversed() ? 'text-gray-400 line-through' : '' }}">
                            <td class="py-2 pr-4 font-medium text-gray-700">{{ \App\Models\BasSettlement::typeLabel($settlement->type ?: \App\Models\BasSettlement::TYPE_GST) }}</td>
                            <td class="py-2 pr-4">{{ $settlement->as_at->format('d M Y') }}</td>
                            <td class="py-2 pr-4">{{ $settlement->settled_at->format('d M Y') }}</td>
                            <td class="py-2 pr-4 text-right">${{ number_format($settlement->gst_payable, 2) }}</td>
                            <td class="py-2 pr-4 text-right">${{ number_format($settlement->gst_receivable, 2) }}</td>
                            <td class="py-2 pr-4 text-right font-medium">${{ number_format($settlement->bank_amount, 2) }}</td>
                            <td class="py-2 pr-4">
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                                    {{ $settlement->direction === \App\Models\BasSettlement::DIRECTION_PAY ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                    {{ $settlement->direction === \App\Models\BasSettlement::DIRECTION_PAY ? 'paid to ATO' : 'refund' }}
                                </span>
                            </td>
                            <td class="py-2 pr-4 text-gray-500">{{ $settlement->reference }}</td>
                            <td class="py-2 text-right">
                                @if ($settlement->isReversed())
                                    <span class="text-xs text-gray-400">reversed {{ $settlement->reversed_at->format('d M Y') }}</span>
                                @else
                                    <form method="POST" action="{{ route('bas-settlements.reverse', $settlement) }}"
                                        onsubmit="return confirm('Reverse this settlement? The GST balances will be restored.')">
                                        @csrf
                                        <button type="submit"
                                            class="text-xs text-red-600 hover:text-red-800 underline">Reverse</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection

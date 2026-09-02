@extends('layouts.app')
@section('title', 'Franking Account')
@section('content')

    <div class="mb-6 flex flex-wrap justify-between items-center gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Franking Account</h1>
            <p class="text-sm text-gray-500 mt-1">
                Notional tracking of franking credits and debits — never posted to the GL. Financial year
                FY{{ $year }} runs 1 Jul {{ $year }} – 30 Jun {{ $year + 1 }}; the balance carries forward.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('franking-account.disclosure', ['year' => $year]) }}"
                class="text-sm text-indigo-600 hover:underline">AASB 1054 disclosure</a>
            <form method="GET" action="{{ route('franking-account.index') }}" class="flex gap-2">
                <select name="year" onchange="this.form.submit()"
                    class="border-gray-300 rounded-lg text-sm">
                    @foreach($years as $y)
                        <option value="{{ $y }}" {{ $y === $year ? 'selected' : '' }}>FY{{ $y }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    @if($deficit)
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <strong>Franking deficit:</strong> FY{{ $year }} closes at ${{ number_format($closingBalance, 2) }}.
            Franking deficit tax (FDT) is payable — handled manually per policy; record the payment as an
            <em>FDT paid</em> entry once assessed.
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Opening balance</p>
            <p class="text-xl font-bold text-gray-800 mt-1">${{ number_format($openingBalance, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Closing balance</p>
            <p class="text-xl font-bold {{ $closingBalance < 0 ? 'text-red-600' : 'text-gray-800' }} mt-1">
                ${{ number_format($closingBalance, 2) }}
            </p>
        </div>
        <div class="bg-indigo-50 rounded-lg p-5">
            <p class="text-xs font-medium text-indigo-700 uppercase">Available for new dividends</p>
            <p class="text-xl font-bold text-indigo-800 mt-1">${{ number_format($availableBalance, 2) }}</p>
            <p class="text-xs text-indigo-600 mt-1">After approved-but-unpaid dividends and estimated entries</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <p class="text-xs font-medium text-gray-500 uppercase">Movements FY{{ $year }}</p>
            <ul class="mt-1 text-sm">
                @forelse($movements as $type => $net)
                    <li class="flex justify-between">
                        <span class="text-gray-500">{{ \App\Models\FrankingAccountEntry::types()[$type] ?? $type }}</span>
                        <span class="font-medium {{ $net < 0 ? 'text-red-600' : 'text-gray-800' }}">{{ number_format($net, 2) }}</span>
                    </li>
                @empty
                    <li class="text-gray-400">No movements</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
            <h2 class="font-semibold text-gray-800">Entries</h2>
            <p class="text-xs text-gray-400">Estimated entries are shown for AASB 1054 disclosure and do not move the balance.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr class="bg-gray-50/50">
                        <td colspan="6" class="px-4 py-2 text-gray-500 italic">Balance carried forward</td>
                        <td class="px-4 py-2 text-right font-medium">${{ number_format($carryForward, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse($entries as $entry)
                        <tr class="{{ $entry->is_estimated ? 'text-gray-400 italic' : '' }}">
                            <td class="px-4 py-2">{{ $entry->entry_date->format('d M Y') }}</td>
                            <td class="px-4 py-2">
                                {{ $entry->typeLabel() }}
                                @if($entry->is_estimated)
                                    <span class="ml-1 px-1.5 py-0.5 text-[10px] rounded bg-amber-100 text-amber-700">EST</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $entry->reference ?: '—' }}</td>
                            <td class="px-4 py-2">{{ $entry->description ?: '—' }}</td>
                            <td class="px-4 py-2 text-right text-green-700">{{ $entry->credit_amount > 0 ? number_format((float) $entry->credit_amount, 2) : '' }}</td>
                            <td class="px-4 py-2 text-right text-red-600">{{ $entry->debit_amount > 0 ? number_format((float) $entry->debit_amount, 2) : '' }}</td>
                            <td class="px-4 py-2 text-right font-medium">
                                {{ $entry->running_balance !== null ? number_format($entry->running_balance, 2) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if(in_array($entry->entry_type, \App\Models\FrankingAccountEntry::MANUAL_TYPES))
                                    <form method="POST" action="{{ route('franking-account.destroy', $entry) }}"
                                        onsubmit="return confirm('Delete this franking entry?');">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-800 text-xs font-medium">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">No entries.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-semibold text-gray-800 mb-4">Add entry</h2>
        <form method="POST" action="{{ route('franking-account.store') }}" class="grid grid-cols-2 md:grid-cols-8 gap-3 items-end">
            @csrf
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Date</label>
                <input type="date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}" required
                    class="w-full border-gray-300 rounded-lg text-sm">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                <select name="entry_type" class="w-full border-gray-300 rounded-lg text-sm">
                    @foreach(\App\Models\FrankingAccountEntry::manualTypes() as $value => $label)
                        <option value="{{ $value }}" {{ old('entry_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Reference</label>
                <input type="text" name="reference" maxlength="20" value="{{ old('reference') }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                <input type="text" name="description" maxlength="100" value="{{ old('description') }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Credit +</label>
                <input type="number" name="credit_amount" step="0.01" min="0" value="{{ old('credit_amount', '0.00') }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
            </div>
            <div class="col-span-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Debit −</label>
                <input type="number" name="debit_amount" step="0.01" min="0" value="{{ old('debit_amount', '0.00') }}"
                    class="w-full border-gray-300 rounded-lg text-sm">
            </div>
            <div class="col-span-1 flex flex-col gap-2">
                <label class="flex items-center gap-1 text-xs text-gray-600">
                    <input type="checkbox" name="is_estimated" value="1" class="rounded"> Estimated
                </label>
                <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                    Record
                </button>
            </div>
        </form>
        <p class="text-xs text-gray-400 mt-3">
            Exactly one of credit or debit must be non-zero. Income tax paid, dividends received and FDT paid are
            credits; tax refunds are debits. Opening balance entries are dated the day before the financial year
            they open (e.g. 30 Jun), one per year, and carry forward — a debit opening records a brought-forward
            deficit.
        </p>
    </div>
@endsection

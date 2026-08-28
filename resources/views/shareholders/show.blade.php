@extends('layouts.app')
@section('title', $shareholder->name)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $shareholder->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $shareholder->addressLine() ?: 'No address on file' }} ·
                {{ $shareholder->resident_for_tax ? 'Resident for tax' : 'Non-resident' }}
            </p>
        </div>
        <a href="{{ route('shareholders.index') }}" class="text-sm text-indigo-600 hover:underline">← All shareholders</a>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Master data --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Master data</h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Email</dt><dd>{{ $shareholder->email ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $shareholder->phone ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Contact</dt><dd>{{ $shareholder->contact_name ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">ABN</dt><dd>{{ $shareholder->abn ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">TFN</dt><dd>{{ $shareholder->tfn ? 'On file' : '—' }}</dd></div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Bank</dt>
                    <dd>{{ $shareholder->bankDetailsComplete()
                        ? $shareholder->bank_bsb . ' / ' . $shareholder->bank_account_number
                        : 'Incomplete' }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-gray-400">
                Edit master data on the
                <a href="{{ route('company-profile.index') }}" class="text-indigo-600 hover:underline">company profile</a> screen.
            </p>

            <h3 class="font-semibold text-gray-700 mt-6 mb-2">Current holdings</h3>
            <ul class="text-sm space-y-1">
                @forelse($holdings as $holding)
                    <li class="flex justify-between">
                        <span>{{ $holding['class']->code }} — {{ $holding['class']->description }}</span>
                        <span class="font-medium">{{ number_format($holding['quantity']) }}</span>
                    </li>
                @empty
                    <li class="text-gray-400">No holdings</li>
                @endforelse
            </ul>
        </div>

        {{-- Shareholding ledger --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Shareholding transactions</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Quantity</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit price</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($shareholder->shareholdings as $holding)
                                <tr>
                                    <td class="px-3 py-2">{{ $holding->transaction_date->format('d M Y') }}</td>
                                    <td class="px-3 py-2">{{ \App\Models\Shareholding::types()[$holding->transaction_type] ?? $holding->transaction_type }}</td>
                                    <td class="px-3 py-2">{{ $holding->shareClass?->code }}</td>
                                    <td class="px-3 py-2 text-right font-medium {{ $holding->quantity < 0 ? 'text-red-600' : 'text-gray-900' }}">
                                        {{ number_format($holding->quantity) }}
                                    </td>
                                    <td class="px-3 py-2 text-right">{{ $holding->unit_price ? '$' . number_format((float) $holding->unit_price, 4) : '—' }}</td>
                                    <td class="px-3 py-2">{{ $holding->reference ?: '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="px-2 py-0.5 text-xs rounded-full {{ $holding->status === \App\Models\Shareholding::STATUS_ACTIVE ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500' }}">
                                            {{ $holding->status === \App\Models\Shareholding::STATUS_ACTIVE ? 'Active' : 'Cancelled' }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        @if($holding->status === \App\Models\Shareholding::STATUS_ACTIVE)
                                            <form method="POST" action="{{ route('shareholders.shareholdings.cancel', [$shareholder, $holding]) }}"
                                                onsubmit="return confirm('Cancel this shareholding transaction?');">
                                                @csrf
                                                <button class="text-red-600 hover:text-red-800 text-xs font-medium">Cancel</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">No transactions recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <form method="POST" action="{{ route('shareholders.shareholdings.store', $shareholder) }}"
                    class="mt-6 grid grid-cols-2 md:grid-cols-7 gap-3 items-end border-t pt-4">
                    @csrf
                    <div class="col-span-1">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Date</label>
                        <input type="date" name="transaction_date" value="{{ old('transaction_date', now()->toDateString()) }}" required
                            class="w-full border-gray-300 rounded-lg text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div class="col-span-1">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Type</label>
                        <select name="transaction_type" class="w-full border-gray-300 rounded-lg text-sm">
                            @foreach(\App\Models\Shareholding::types() as $value => $label)
                                <option value="{{ $value }}" {{ old('transaction_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-1">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Class</label>
                        <select name="share_class_id" class="w-full border-gray-300 rounded-lg text-sm" required>
                            @foreach($shareClasses as $class)
                                <option value="{{ $class->id }}">{{ $class->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-1">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Quantity ±</label>
                        <input type="number" name="quantity" value="{{ old('quantity') }}" required
                            placeholder="e.g. 1000 or -500"
                            class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                    <div class="col-span-1">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Unit price</label>
                        <input type="number" name="unit_price" step="0.0001" min="0" value="{{ old('unit_price') }}"
                            class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                    <div class="col-span-1">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Reference</label>
                        <input type="text" name="reference" maxlength="20" value="{{ old('reference') }}"
                            class="w-full border-gray-300 rounded-lg text-sm">
                    </div>
                    <div class="col-span-1">
                        <button class="w-full bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-lg text-sm font-medium">
                            Record
                        </button>
                    </div>
                </form>
            </div>

            {{-- Dividend history --}}
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Dividend history</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Declaration</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Paid</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Shares</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cash</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Franking credit</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Grossed-up</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Statement</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($dividendHistory as $distribution)
                                <tr>
                                    <td class="px-3 py-2">
                                        <a href="{{ route('dividends.show', $distribution->declaration) }}" class="text-indigo-600 hover:underline">
                                            {{ $distribution->declaration->declaration_number }}
                                        </a>
                                    </td>
                                    <td class="px-3 py-2">{{ $distribution->declaration->payment_date->format('d M Y') }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($distribution->shares_eligible) }}</td>
                                    <td class="px-3 py-2 text-right">${{ number_format((float) $distribution->cash_dividend, 2) }}</td>
                                    <td class="px-3 py-2 text-right">${{ number_format((float) $distribution->franking_credit, 2) }}</td>
                                    <td class="px-3 py-2 text-right">${{ number_format((float) $distribution->grossed_up_dividend, 2) }}</td>
                                    <td class="px-3 py-2">
                                        @if($distribution->status === \App\Models\DividendDistribution::STATUS_PAID)
                                            <a href="{{ route('dividends.statements.pdf', $distribution) }}"
                                                class="text-indigo-600 hover:underline">PDF</a>
                                            @if($distribution->statement_sent)
                                                <span class="ml-1 text-xs text-green-600">sent</span>
                                            @endif
                                        @else
                                            <span class="text-gray-400">{{ $distribution->status }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">No dividends paid yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@extends('reports.layout')

@php
    // Whole-dollar, sign-aware formatting per spec V08 (e.g. -$239).
    $money = fn ($amount) => $amount === null ? '—' : ($amount < 0 ? '-' : '') . '$' . number_format(abs($amount));
@endphp

@section('title', 'Company Tax Return')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">Company Tax Return — Annual Report (ATO NAT 0656)</h2>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            <!-- Filters -->
            <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="fy" class="block text-sm font-medium text-gray-700 mb-1">Income year ended 30 June</label>
                    <select name="fy" id="fy"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($availableFys as $year)
                            <option value="{{ $year }}" {{ (int) $fyEnd === $year ? 'selected' : '' }}>
                                {{ $year }} (Jul {{ $year - 1 }} – Jun {{ $year }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                        Generate Report
                    </button>
                    <a href="{{ route('reports.export.company-tax.pdf', ['fy' => $fyEnd]) }}"
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm">PDF</a>
                    <a href="{{ route('reports.export.company-tax.excel', ['fy' => $fyEnd]) }}"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-sm">Excel</a>
                    <a href="{{ route('reports.export.company-tax.csv', ['fy' => $fyEnd]) }}"
                        class="px-4 py-2 bg-slate-600 text-white rounded-md hover:bg-slate-700 text-sm">CSV</a>
                </div>
            </form>

            <div class="border-t pt-6 space-y-8">
                <!-- Entity header -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">{{ $statement['entity']['name'] }}</h3>
                    <p class="text-sm text-gray-600">
                        Income year: {{ $statement['fyStart']->format('d/m/Y') }} to {{ $statement['fyEnd']->format('d/m/Y') }} ·
                        ABN: {{ $statement['entity']['abn'] !== '' ? $statement['entity']['abn'] : 'not configured' }} ·
                        TFN: {{ $statement['entity']['tfn'] !== '' ? $statement['entity']['tfn'] : 'not configured' }}
                    </p>
                    @if ($statement['entity']['abn'] === '' || $statement['entity']['tfn'] === '')
                        <p class="mt-2 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                            Set <code>COMPANY_ABN</code> and <code>COMPANY_TFN</code> in the environment to complete the
                            return's identification section.
                        </p>
                    @endif
                </div>

                <!-- Summary cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-green-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-green-700">6-S — Total income</p>
                        <p class="text-2xl font-bold text-green-800 mt-1">{{ $money($statement['totalIncome']) }}</p>
                    </div>
                    <div class="bg-red-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-red-700">6-Q — Total expenses</p>
                        <p class="text-2xl font-bold text-red-800 mt-1">{{ $money($statement['totalExpenses']) }}</p>
                    </div>
                    <div class="bg-indigo-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-indigo-700">6-T — Total profit or loss</p>
                        <p class="text-2xl font-bold text-indigo-800 mt-1">{{ $money($statement['profitOrLoss']) }}</p>
                    </div>
                    <div class="bg-slate-100 rounded-lg p-6">
                        <p class="text-sm font-medium text-slate-700">7-T — Taxable income</p>
                        <p class="text-2xl font-bold text-slate-800 mt-1">{{ $money($statement['taxableIncome']) }}</p>
                        <p class="text-xs text-slate-600 mt-1">Est. tax @ {{ $statement['taxRate'] }}%: {{ $money($statement['estimatedTax']) }}</p>
                    </div>
                </div>

                @if (!empty($statement['warnings']))
                    <div class="bg-amber-50 border border-amber-200 rounded-md px-4 py-3">
                        <p class="text-sm font-semibold text-amber-800 mb-1">Warnings</p>
                        <ul class="text-sm text-amber-800 list-disc list-inside space-y-1">
                            @foreach ($statement['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <!-- Item 6 Income -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Item 6 — Income</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Label</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount (AUD)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($statement['income'] as $row)
                                    <tr class="{{ $row['total'] ? 'font-semibold bg-gray-50' : '' }}">
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $row['label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            {{ $row['name'] }}
                                            @foreach ($row['accounts'] as $account)
                                                <span class="block text-xs text-gray-500">{{ $account['code'] }} {{ $account['name'] }} — {{ $money($account['amount']) }}</span>
                                            @endforeach
                                            @if ($row['note'])
                                                <span class="block text-xs text-gray-400">{{ $row['note'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ $money($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Item 6 Expenses -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Item 6 — Expenses <span class="text-xs font-normal text-gray-500">(GST-exclusive cash payments)</span></h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Label</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount (AUD)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($statement['expenses'] as $row)
                                    <tr class="{{ $row['total'] ? 'font-semibold bg-gray-50' : '' }}">
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $row['label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            {{ $row['name'] }}
                                            @foreach ($row['accounts'] as $account)
                                                <span class="block text-xs text-gray-500">{{ $account['code'] }} {{ $account['name'] }} — {{ $money($account['amount']) }}</span>
                                            @endforeach
                                            @if ($row['note'])
                                                <span class="block text-xs text-gray-400">{{ $row['note'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ $money($row['amount']) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="font-semibold bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-900">T</td>
                                    <td class="px-4 py-3 text-sm text-gray-900">Total profit or loss</td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ $money($statement['profitOrLoss']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Item 7 Reconciliation -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Item 7 — Reconciliation to taxable income or loss</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Label</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount (AUD)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($statement['reconciliation'] as $row)
                                    <tr class="{{ $row['total'] ? 'font-semibold bg-gray-50' : '' }}">
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $row['label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            {{ $row['name'] }}
                                            @if ($row['note'])
                                                <span class="block text-xs text-gray-400">{{ $row['note'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                            {{ $money($row['amount']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Item 8 Financial and other information -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Item 8 — Financial and other information</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Label</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount (AUD)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($statement['financialInfo'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $row['label'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">
                                            {{ $row['name'] }}
                                            @if ($row['note'])
                                                <span class="block text-xs text-gray-400">{{ $row['note'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                            {{ $money($row['amount']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Item 10 capital purchases -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Item 10 — SBE simplified depreciation (capital purchases reference)</h4>
                    <p class="text-sm text-gray-600 mb-2">
                        Cash paid for capital assets during the year. Use these figures to complete labels 10-A (instant asset
                        write-off) and 10-B (general small business pool) — the deductible split is a manual judgement.
                        Total: <span class="font-semibold">{{ $money($statement['capitalPurchases']['total']) }}</span>
                    </p>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Asset account</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net paid (AUD)</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse ($statement['capitalPurchases']['accounts'] as $account)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $account['code'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $account['name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ $money($account['amount']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-4 py-3 text-sm text-gray-500">No capital purchases in the period.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- GST / bank cross-checks -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-700">
                        <p class="font-semibold mb-1">GST cross-check (excluded from labels)</p>
                        <p>GST collected (credits to GST payable): {{ $money($statement['gst']['collected']) }}</p>
                        <p>GST paid (debits to GST payable): {{ $money($statement['gst']['paid']) }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-700">
                        <p class="font-semibold mb-1">Bank movement cross-check</p>
                        <p>Inflows: {{ $money($statement['bank']['inflows']) }} · Outflows: {{ $money($statement['bank']['outflows']) }}</p>
                        <p class="text-xs text-gray-500 mt-1">Validations V05/V06 reconcile these to the label totals — see below.</p>
                    </div>
                </div>

                <!-- Validations -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-2">Validation rules</h4>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Rule</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Detail</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($statement['validations'] as $validation)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $validation['code'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ $validation['description'] }}</td>
                                        <td class="px-4 py-3 text-sm font-medium {{ $validation['status'] === 'PASS' ? 'text-green-600' : 'text-red-600' }}">{{ $validation['status'] }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-600">{{ $validation['detail'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Assumptions -->
                <div class="mt-6 text-xs text-gray-500 space-y-1">
                    <p>Amounts are cash-basis (recognised when received/paid) and exclude GST, per the small business entity rules; label letters follow the Company tax return 2026 (NAT 0656).</p>
                    <p>Only bank-settled ledger transactions are included; non-cash journals (depreciation, revaluations, forex) are excluded — see validation V07.</p>
                    <p>Unlike the BAS report (which uses invoice/bill dates), this report reads cash ledger posting dates.</p>
                    <p>Franking account balances (labels 8-P/8-M) are not tracked — the franking module is not implemented.</p>
                    <p>Motor vehicle private-use reductions (6-Y) and instant asset write-off eligibility (Item 10) are manual judgements outside this system.</p>
                    <p>The tax estimate is informational — the Calculation statement must be completed against the applicable company tax rate (25% base rate entity / 30%).</p>
                </div>
            </div>
        </div>
    </div>
@endsection

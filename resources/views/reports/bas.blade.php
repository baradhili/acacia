@extends('reports.layout')

@section('title', 'BAS')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">BAS — Business Activity Statement</h2>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            <!-- Filters -->
            <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="fy" class="block text-sm font-medium text-gray-700 mb-1">Financial Year</label>
                    <select name="fy" id="fy"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($availableFys as $year)
                            <option value="{{ $year }}" {{ (int) $fyEnd === $year ? 'selected' : '' }}>
                                FY{{ $year }} (Jul {{ $year - 1 }} – Jun {{ $year }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                        Generate Report
                    </button>
                    <a href="{{ route('reports.export.bas.pdf', ['fy' => $fyEnd]) }}"
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm">PDF</a>
                    <a href="{{ route('reports.export.bas.excel', ['fy' => $fyEnd]) }}"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-sm">Excel</a>
                </div>
            </form>

            <div class="border-t pt-6">
                <!-- Report Header -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800">BAS Summary by Quarter</h3>
                    <p class="text-sm text-gray-600">
                        Period: {{ $statement['fyStart']->format('d/m/Y') }} to {{ $statement['fyEnd']->format('d/m/Y') }}
                    </p>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <div class="bg-green-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-green-700">1A — GST on Sales (FY total)</p>
                        <p class="text-2xl font-bold text-green-800 mt-1">
                            ${{ number_format($statement['totals']['gst_sales'], 2) }}
                        </p>
                    </div>
                    <div class="bg-red-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-red-700">1B — GST on Purchases (FY total)</p>
                        <p class="text-2xl font-bold text-red-800 mt-1">
                            ${{ number_format($statement['totals']['gst_purchases'], 2) }}
                        </p>
                    </div>
                    <div class="bg-indigo-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-indigo-700">Net GST for the year</p>
                        <p class="text-2xl font-bold text-indigo-800 mt-1">
                            ${{ number_format($statement['totals']['net'], 2) }}
                        </p>
                        <p class="text-xs text-indigo-600 mt-1">
                            {{ $statement['totals']['net'] >= 0 ? 'Payable to ATO' : 'Refundable from ATO' }}
                        </p>
                    </div>
                </div>

                <!-- Quarterly table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quarter</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Period</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">G1 Total sales (incl GST)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">G11 Purchases (incl GST)</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">1A GST on sales</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">1B GST on purchases</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net GST</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($statement['quarters'] as $q)
                                <tr>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $q['label'] }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600">{{ $q['start']->format('d/m/Y') }} – {{ $q['end']->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($q['g1'], 2) }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($q['g11'], 2) }}</td>
                                    <td class="px-4 py-3 text-sm text-green-600 text-right font-medium">${{ number_format($q['gst_sales'], 2) }}</td>
                                    <td class="px-4 py-3 text-sm text-red-600 text-right font-medium">${{ number_format($q['gst_purchases'], 2) }}</td>
                                    <td class="px-4 py-3 text-sm text-right font-medium {{ $q['net'] >= 0 ? 'text-gray-900' : 'text-indigo-600' }}">
                                        ${{ number_format($q['net'], 2) }}
                                        <span class="block text-xs font-normal {{ $q['net'] >= 0 ? 'text-gray-500' : 'text-indigo-500' }}">
                                            {{ $q['net'] >= 0 ? 'payable' : 'refundable' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-800" colspan="2">FY{{ $fyEnd }} Total</td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">${{ number_format($statement['totals']['g1'], 2) }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">${{ number_format($statement['totals']['g11'], 2) }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-green-600 text-right">${{ number_format($statement['totals']['gst_sales'], 2) }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-red-600 text-right">${{ number_format($statement['totals']['gst_purchases'], 2) }}</td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">${{ number_format($statement['totals']['net'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Assumptions -->
                <div class="mt-6 text-xs text-gray-500 space-y-1">
                    <p>G13 non-capital purchases equals G11 — capital purchases cannot be distinguished from bill data, so G10 is treated as nil.</p>
                    <p>Credit notes are excluded (no GST split is stored for them). Draft and cancelled invoices/bills are excluded.</p>
                    <p>W1/W2 (PAYG withholding) is not shown — the system keeps no payroll ledger.</p>
                </div>
            </div>
        </div>
    </div>
@endsection

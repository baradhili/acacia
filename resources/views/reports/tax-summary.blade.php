@extends('reports.layout')

@section('title', 'Tax Summary')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">Tax Summary Report</h2>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            <!-- Filters -->
            <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                    <input type="date" name="start_date" id="start_date" 
                        value="{{ $startDate->format('Y-m-d') }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                    <input type="date" name="end_date" id="end_date" 
                        value="{{ $endDate->format('Y-m-d') }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                        Generate Report
                    </button>
                </div>
            </form>

            <div class="border-t pt-6">
                <!-- Report Header -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800">Tax Summary</h3>
                    <p class="text-sm text-gray-600">
                        Period: {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}
                    </p>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <div class="bg-green-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-green-700">Output Tax (GST Collected)</p>
                        <p class="text-2xl font-bold text-green-800 mt-1">
                            ${{ number_format($totalSalesTax, 2) }}
                        </p>
                        <p class="text-xs text-green-600 mt-1">
                            From {{ $salesByTaxRate->sum('transaction_count') }} invoice line(s)
                        </p>
                    </div>
                    <div class="bg-red-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-red-700">Input Tax (GST Paid)</p>
                        <p class="text-2xl font-bold text-red-800 mt-1">
                            ${{ number_format($totalPurchaseTax, 2) }}
                        </p>
                        <p class="text-xs text-red-600 mt-1">
                            From {{ $purchasesByTaxRate->sum('transaction_count') }} expense(s)
                        </p>
                    </div>
                    <div class="bg-indigo-50 rounded-lg p-6">
                        <p class="text-sm font-medium text-indigo-700">Net Tax Payable</p>
                        <p class="text-2xl font-bold text-indigo-800 mt-1">
                            ${{ number_format($netTaxPayable, 2) }}
                        </p>
                        <p class="text-xs text-indigo-600 mt-1">
                            {{ $netTaxPayable >= 0 ? 'You owe' : 'You are owed' }}
                        </p>
                    </div>
                </div>

                <!-- GST Collected by Rate -->
                <div class="mb-8">
                    <h4 class="text-lg font-semibold text-gray-800 mb-4">Sales by Tax Rate (Output Tax)</h4>
                    @if($salesByTaxRate->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tax Rate</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net Amount</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Tax Amount</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Gross Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($salesByTaxRate as $row)
                                        <tr>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $row['tax_rate'] }}%</td>
                                            <td class="px-4 py-3 text-sm text-gray-600 text-right">{{ $row['transaction_count'] }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($row['net_amount'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-green-600 text-right font-medium">${{ number_format($row['tax_amount'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($row['gross_amount'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800">Total</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">{{ $salesByTaxRate->sum('transaction_count') }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">${{ number_format($salesByTaxRate->sum('net_amount'), 2) }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-green-600 text-right">${{ number_format($totalSalesTax, 2) }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">${{ number_format($salesByTaxRate->sum('gross_amount'), 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @else
                        <p class="text-center text-gray-500 py-4">No sales with tax found for this period.</p>
                    @endif
                </div>

                <!-- GST Paid by Rate -->
                <div class="mb-8">
                    <h4 class="text-lg font-semibold text-gray-800 mb-4">Purchases by Tax Rate (Input Tax)</h4>
                    @if($purchasesByTaxRate->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tax Rate</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Transactions</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net Amount</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Tax Amount</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Gross Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($purchasesByTaxRate as $row)
                                        <tr>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $row['tax_rate'] }}%</td>
                                            <td class="px-4 py-3 text-sm text-gray-600 text-right">{{ $row['transaction_count'] }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($row['net_amount'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-red-600 text-right font-medium">${{ number_format($row['tax_amount'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($row['gross_amount'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800">Total</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">{{ $purchasesByTaxRate->sum('transaction_count') }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">${{ number_format($purchasesByTaxRate->sum('net_amount'), 2) }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-red-600 text-right">${{ number_format($totalPurchaseTax, 2) }}</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">${{ number_format($purchasesByTaxRate->sum('gross_amount'), 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @else
                        <p class="text-center text-gray-500 py-4">No purchases with tax found for this period.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

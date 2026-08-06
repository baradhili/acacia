@extends('reports.layout')

@section('title', 'Income Statement')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Income Statement (Profit & Loss)</h1>
            <p class="report-subtitle">For the period {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.income-statement') }}" class="report-filters">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="{{ $startDate->format('Y-m-d') }}"
                    class="rounded-md border-gray-300 shadow-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="{{ $endDate->format('Y-m-d') }}"
                    class="rounded-md border-gray-300 shadow-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Generate
                </button>
            </div>
        </form>

        @if(isset($lines['statement']))
        <div class="space-y-6">
            <!-- Revenue -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Revenue</h3>
                @if(isset($lines['statement']['revenue']))
                <table class="report-table">
                    <tbody>
                        @foreach($lines['statement']['revenue'] as $item)
                            <tr>
                                <td>{{ $item['account']['name'] ?? 'Revenue' }}</td>
                                <td class="text-right">${{ number_format($item['balance'] ?? 0, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Total Revenue</td>
                            <td class="text-right font-bold">${{ number_format($lines['statement']['revenueTotal'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
                @else
                    <p class="text-gray-500 italic">No revenue recorded</p>
                @endif
            </div>

            <!-- Direct Costs -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Direct Costs</h3>
                @if(isset($lines['statement']['direct_costs']))
                <table class="report-table">
                    <tbody>
                        @foreach($lines['statement']['direct_costs'] as $item)
                            <tr>
                                <td>{{ $item['account']['name'] ?? 'Direct Costs' }}</td>
                                <td class="text-right">(${{ number_format(abs($item['balance'] ?? 0), 2) }})</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Total Direct Costs</td>
                            <td class="text-right font-bold">(${{ number_format(abs($lines['statement']['directCostsTotal'] ?? 0), 2) }})</td>
                        </tr>
                    </tfoot>
                </table>
                @else
                    <p class="text-gray-500 italic">No direct costs recorded</p>
                @endif
            </div>

            <!-- Gross Profit -->
            <div class="border-t-2 border-gray-300 pt-4">
                <div class="flex justify-between items-center">
                    <span class="text-xl font-bold text-gray-800">Gross Profit</span>
                    <span class="text-xl font-bold text-green-600">${{ number_format($lines['statement']['grossProfit'] ?? 0, 2) }}</span>
                </div>
            </div>

            <!-- Expenses -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Operating Expenses</h3>
                @if(isset($lines['statement']['expense']))
                <table class="report-table">
                    <tbody>
                        @foreach($lines['statement']['expense'] as $item)
                            <tr>
                                <td>{{ $item['account']['name'] ?? 'Expense' }}</td>
                                <td class="text-right">(${{ number_format(abs($item['balance'] ?? 0), 2) }})</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Total Expenses</td>
                            <td class="text-right font-bold">(${{ number_format(abs($lines['statement']['expenseTotal'] ?? 0), 2) }})</td>
                        </tr>
                    </tfoot>
                </table>
                @else
                    <p class="text-gray-500 italic">No expenses recorded</p>
                @endif
            </div>

            <!-- Net Profit -->
            <div class="border-t-2 border-gray-300 pt-4">
                <div class="flex justify-between items-center">
                    <span class="text-xl font-bold text-gray-800">Net Profit</span>
                    <span class="text-xl font-bold {{ ($lines['statement']['netProfit'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        ${{ number_format($lines['statement']['netProfit'] ?? 0, 2) }}
                    </span>
                </div>
            </div>
        </div>
        @else
            <p class="text-gray-500 italic">No financial data available for this period</p>
        @endif
    </div>
@endsection

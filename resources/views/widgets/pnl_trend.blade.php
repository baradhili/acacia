<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-lg font-semibold text-gray-800">P&L Trend (12 Months)</h2>
        <a href="{{ route('reports.income-statement') }}" class="text-sm text-blue-600 hover:text-blue-800">Full Report</a>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <p class="text-sm text-gray-500">Total Revenue</p>
                <p class="text-lg font-medium text-green-600">${{ number_format($total_revenue, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Total Expenses</p>
                <p class="text-lg font-medium text-red-600">${{ number_format($total_expenses, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Net Income</p>
                <p class="text-lg font-medium {{ $total_net_income >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    ${{ number_format($total_net_income, 2) }}
                </p>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-4 pt-4 border-t">
            <div>
                <p class="text-xs text-gray-500">Avg Revenue/Month</p>
                <p class="text-sm font-medium">${{ number_format($avg_revenue, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Avg Expenses/Month</p>
                <p class="text-sm font-medium">${{ number_format($avg_expenses, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Avg Net Income</p>
                <p class="text-sm font-medium">${{ number_format($avg_net_income, 2) }}</p>
            </div>
        </div>
    </div>
</div>

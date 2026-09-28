<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">{{ __('widgets.labels.pnl_trend') }}</h2>
        <a href="{{ route('reports.income-statement') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('widgets.pnl_trend.full_report') }}</a>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <p class="text-sm text-gray-500">{{ __('widgets.pnl_trend.total_revenue') }}</p>
                <p class="text-lg font-medium text-green-600">${{ number_format($total_revenue, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">{{ __('widgets.pnl_trend.total_expenses') }}</p>
                <p class="text-lg font-medium text-red-600">${{ number_format($total_expenses, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">{{ __('widgets.pnl_trend.net_income') }}</p>
                <p class="text-lg font-medium {{ $total_net_income >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    ${{ number_format($total_net_income, 2) }}
                </p>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-4 pt-4 border-t">
            <div>
                <p class="text-xs text-gray-500">{{ __('widgets.pnl_trend.avg_revenue') }}</p>
                <p class="text-sm font-medium">${{ number_format($avg_revenue, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ __('widgets.pnl_trend.avg_expenses') }}</p>
                <p class="text-sm font-medium">${{ number_format($avg_expenses, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">{{ __('widgets.pnl_trend.avg_net_income') }}</p>
                <p class="text-sm font-medium">${{ number_format($avg_net_income, 2) }}</p>
            </div>
        </div>
    </div>
</div>

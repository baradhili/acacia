<div class="bg-white rounded-lg shadow">
    <div class="widget-handle px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50 cursor-grab">
        <h2 class="text-lg font-semibold text-gray-800">{{ __('widgets.labels.bank_balance') }}</h2>
        <a href="{{ route('reconciliation.index') }}" class="text-sm text-blue-600 hover:text-blue-800">{{ __('widgets.reconcile') }}</a>
    </div>
    <div class="p-6">
        <div class="mb-4">
            <p class="text-sm text-gray-500">{{ __('widgets.bank_balance.total_balance') }}</p>
            <p class="text-2xl font-bold text-gray-800">${{ $balance_formatted }}</p>
        </div>
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <p class="text-sm text-gray-500">{{ __('widgets.bank_balance.credits') }}</p>
                <p class="text-lg font-medium text-green-600">${{ $total_credits_formatted }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">{{ __('widgets.bank_balance.debits') }}</p>
                <p class="text-lg font-medium text-red-600">${{ $total_debits_formatted }}</p>
            </div>
        </div>
        <div class="border-t pt-4">
            <p class="text-sm text-gray-500 mb-2">{{ __('widgets.bank_balance.status') }}</p>
            <div class="flex gap-4 text-sm">
                <span class="text-yellow-600">{{ __('widgets.bank_balance.unreconciled', ['count' => $unreconciled_count]) }}</span>
                <span class="text-green-600">{{ __('widgets.bank_balance.matched', ['count' => $matched_count]) }}</span>
                <span class="text-gray-500">{{ __('widgets.bank_balance.ignored', ['count' => $ignored_count]) }}</span>
            </div>
        </div>
    </div>
</div>

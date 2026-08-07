<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-lg font-semibold text-gray-800">Bank Balance</h2>
        <a href="{{ route('reconciliation.index') }}" class="text-sm text-blue-600 hover:text-blue-800">Reconcile</a>
    </div>
    <div class="p-6">
        <div class="mb-4">
            <p class="text-sm text-gray-500">Total Balance</p>
            <p class="text-2xl font-bold text-gray-800">${{ $balance_formatted }}</p>
        </div>
        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <p class="text-sm text-gray-500">Credits</p>
                <p class="text-lg font-medium text-green-600">${{ $total_credits_formatted }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Debits</p>
                <p class="text-lg font-medium text-red-600">${{ $total_debits_formatted }}</p>
            </div>
        </div>
        <div class="border-t pt-4">
            <p class="text-sm text-gray-500 mb-2">Reconciliation Status</p>
            <div class="flex gap-4 text-sm">
                <span class="text-yellow-600">{{ $unreconciled_count }} unreconciled</span>
                <span class="text-green-600">{{ $matched_count }} matched</span>
                <span class="text-gray-500">{{ $ignored_count }} ignored</span>
            </div>
        </div>
    </div>
</div>

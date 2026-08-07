<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-800">Cash Flow (30 Days)</h2>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <p class="text-sm text-gray-500">Inflows</p>
                <p class="text-xl font-bold text-green-600">${{ $inflows_formatted }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Outflows</p>
                <p class="text-xl font-bold text-red-600">${{ $outflows_formatted }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Net Flow</p>
                <p class="text-xl font-bold {{ $net_flow >= 0 ? 'text-green-600' : 'text-red-600' }}">${{ $net_flow_formatted }}</p>
            </div>
        </div>
        @if($change_percent != 0)
        <p class="text-sm text-gray-500">
            vs previous period: 
            <span class="{{ $change_percent >= 0 ? 'text-green-600' : 'text-red-600' }}">
                {{ $change_percent > 0 ? '+' : '' }}{{ $change_percent }}%
            </span>
        </p>
        @endif
    </div>
</div>

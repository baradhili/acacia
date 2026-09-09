<div class="bg-white rounded-lg shadow h-full">
    <div class="widget-handle p-4 border-b border-gray-200 bg-gray-50 cursor-grab flex items-center justify-between rounded-t-lg">
        <p class="text-sm font-medium text-gray-600">Unlodged GST</p>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path>
        </svg>
    </div>
    <div class="p-4 flex items-center justify-between">
        <div>
            <p class="text-2xl font-bold {{ $net >= 0 ? 'text-gray-800' : 'text-green-700' }}">
                ${{ number_format(abs($net), 2) }}
                <span class="text-sm font-medium text-gray-500">{{ $net >= 0 ? 'to pay' : 'refund' }}</span>
            </p>
            <p class="text-xs text-gray-500 mt-1">
                Payable ${{ number_format($payable, 2) }} &middot; Receivable ${{ number_format($receivable, 2) }}
            </p>
        </div>
        <div class="p-3 {{ $net >= 0 ? 'bg-red-100' : 'bg-green-100' }} rounded-full">
            <svg class="w-6 h-6 {{ $net >= 0 ? 'text-red-600' : 'text-green-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"></path>
            </svg>
        </div>
    </div>
</div>

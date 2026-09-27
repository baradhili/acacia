<div class="bg-white rounded-lg shadow h-full">
    <div class="widget-handle p-4 border-b border-gray-200 bg-gray-50 cursor-grab flex items-center justify-between rounded-t-lg">
        <p class="text-sm font-medium text-gray-600">Hours This Month</p>
        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
        </svg>
    </div>
    <div class="p-4 flex items-center justify-between">
        <div>
            <p class="text-2xl font-bold text-gray-800">{{ $hours }}</p>
        </div>
        <div class="p-3 bg-purple-100 rounded-full">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
    </div>
</div>

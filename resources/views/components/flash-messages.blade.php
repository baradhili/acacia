@props(['type' => null])

@if (session('success'))
    <div x-data="{ show: true }" x-show="show" x-transition
         class="mb-4 rounded-md bg-green-50 p-4 border border-green-200 flex items-start justify-between">
        <div class="flex">
            <svg class="h-5 w-5 text-green-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
        <button @click="show = false" class="text-green-400 hover:text-green-600">&times;</button>
    </div>
@endif

@if (session('error'))
    <div x-data="{ show: true }" x-show="show" x-transition
         class="mb-4 rounded-md bg-red-50 p-4 border border-red-200 flex items-start justify-between">
        <div class="flex">
            <svg class="h-5 w-5 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 13l4 4L19 7"></path>
            </svg>
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
        <button @click="show = false" class="text-red-400 hover:text-red-600">&times;</button>
    </div>
@endif

@if (session('info'))
    <div x-data="{ show: true }" x-show="show" x-transition
         class="mb-4 rounded-md bg-blue-50 p-4 border border-blue-200 flex items-start justify-between">
        <div class="flex">
            <svg class="h-5 w-5 text-blue-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <p class="text-sm text-blue-800">{{ session('info') }}</p>
        </div>
        <button @click="show = false" class="text-blue-400 hover:text-blue-600">&times;</button>
    </div>
@endif

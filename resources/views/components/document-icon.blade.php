@props(['count' => 0])

@if ($count > 0)
    <span class="inline-flex items-center ml-1.5 align-middle text-gray-400"
          title="{{ $count }} {{ Str::plural('document', $count) }} attached"
          role="img" aria-label="{{ $count }} {{ Str::plural('document', $count) }} attached">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
        </svg>
    </span>
@endif

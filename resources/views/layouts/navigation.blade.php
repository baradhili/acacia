<!-- Sidebar — renders the nav registry (core + module contributions),
     role-filtered and position-ordered by App\Support\Nav. -->
<aside class="w-64 bg-slate-800 text-white shrink-0 flex flex-col min-h-screen sticky top-0 self-start">
    <!-- Logo -->
    <div class="h-16 flex items-center px-6 border-b border-slate-700">
		<a href="{{ route('dashboard') }}" class="flex items-center gap-3">
			<img src="{{ asset('images/logo.svg') }}" alt="Logo" class="h-8 w-auto">
			<span class="text-xl font-bold">Acacia</span>
		</a>
	</div>

    <!-- Navigation -->
    <nav class="mt-6 px-3 overflow-y-auto" style="max-height: calc(100vh - 6rem);">
        @foreach ($sidebarNav as $item)
            @if ($item['type'] === 'heading')
                <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">{{ $item['label'] }}</p>
            @elseif ($item['type'] === 'divider')
                <div class="my-4 border-t border-slate-700"></div>
            @elseif ($item['type'] === 'link')
                @if (!empty($item['add']))
                    <div class="flex items-center justify-between group">
                        <a href="{{ route($item['route']) }}"
                            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs(...(array) $item['active']) ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $item['icon'] !!}</svg>
                            {{ $item['label'] }}
                        </a>
                        <a href="{{ route($item['add']) }}"
                            class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                            title="{{ $item['addTitle'] ?? ('Add ' . $item['label']) }}">+</a>
                    </div>
                @else
                    <a href="{{ route($item['route']) }}"
                        class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs(...(array) $item['active']) ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $item['icon'] ?? '' !!}</svg>
                        {{ $item['label'] }}
                    </a>
                @endif
            @endif
        @endforeach
    </nav>
</aside>

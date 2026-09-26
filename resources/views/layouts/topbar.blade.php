<!-- Top Bar — feature dropdowns render the nav registry (core +
     module contributions), role-filtered by App\Support\Nav; the user
     menu is shell UI and stays literal. -->
<header class="sticky top-0 z-30 h-16 bg-white shadow-sm border-b border-gray-200 flex items-center justify-between px-6 shrink-0">
    <div>
        <h1 class="text-xl font-semibold text-gray-800">@yield('title', 'Dashboard')</h1>
    </div>
    <div class="flex items-center gap-3">
        @foreach ($topbarNav as $item)
            @if ($item['type'] === 'dropdown')
                {{-- Dropdown --}}
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open"
                        class="flex items-center gap-1 px-3 py-2 text-sm font-medium rounded-md transition-colors whitespace-nowrap
                            {{ request()->routeIs(...(array) $item['active']) ? 'text-indigo-600 bg-indigo-50' : 'text-gray-700 hover:text-gray-900 hover:bg-gray-100' }}">
                        {{ $item['label'] }}
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="open" @click.away="open = false"
                        class="absolute right-0 mt-2 w-max min-w-[14rem] max-w-xs bg-white rounded-md shadow-lg py-1 z-50 max-h-[75vh] overflow-y-auto">
                        @foreach ($item['children'] as $child)
                            @if ($child['type'] === 'heading')
                                <p class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider whitespace-nowrap">{{ $child['label'] }}</p>
                            @elseif ($child['type'] === 'divider')
                                <div class="border-t border-gray-100 my-1"></div>
                            @elseif ($child['type'] === 'link')
                                <a href="{{ route($child['route']) }}"
                                    class="block px-4 py-2 text-sm whitespace-nowrap {{ request()->routeIs(...(array) $child['active']) ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                                    {{ $child['label'] }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @elseif ($item['type'] === 'link')
                <a href="{{ route($item['route']) }}"
                    class="flex items-center gap-1 px-3 py-2 text-sm font-medium rounded-md transition-colors
                        {{ request()->routeIs(...(array) $item['active']) ? 'text-indigo-600 bg-indigo-50' : 'text-gray-700 hover:text-gray-900 hover:bg-gray-100' }}">
                    {{ $item['label'] }}
                </a>
            @endif
        @endforeach

        <!-- User Menu -->
        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" class="flex items-center gap-2 text-gray-700 hover:text-gray-900">
                @if(Auth::user()->profile_photo_url)
                    <img src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" class="h-8 w-8 rounded-full object-cover">
                @else
                    <div class="h-8 w-8 rounded-full bg-indigo-600 flex items-center justify-center text-white text-xs font-bold">
                        {{ Auth::user()->initials }}
                    </div>
                @endif
                <span>{{ Auth::user()->name }}</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div x-show="open" @click.away="open = false"
                class="absolute right-0 mt-2 w-max min-w-[12rem] max-w-[16rem] bg-white rounded-md shadow-lg py-1 z-50">
                @role('admin')
                    <p class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider whitespace-nowrap">Admin</p>
                    <a href="{{ route('administration.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                        Administration
                        @if ($entity = \App\Services\IfrsPosting::resolveEntity())
                            <span class="block text-xs text-gray-400">
                                Currently Open Year: FY {{ app(\App\Services\FiscalYearService::class)->currentYear($entity) }}
                            </span>
                        @endif
                    </a>
                    <a href="{{ route('backups.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                        Backups
                    </a>
                    <a href="{{ route('users.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                        Users
                    </a>
                    <a href="{{ route('modules.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                        Modules
                    </a>
                @endrole
                <div class="border-t border-gray-100 my-1"></div>
                <a href="{{ route('profile.edit') }}"
                    class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                    Profile
                </a>
                <button type="button" onclick="window.dispatchEvent(new CustomEvent('toggle-widget-edit'))"
                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                    Customize Dashboard
                </button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 whitespace-nowrap">
                        Log Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

<!-- Top Bar -->
<header class="sticky top-0 z-30 h-16 bg-white shadow-sm border-b border-gray-200 flex items-center justify-between px-6 shrink-0">
    <div>
        <h1 class="text-xl font-semibold text-gray-800">@yield('title', 'Dashboard')</h1>
    </div>
    <div class="flex items-center gap-4">
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
                class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                @role('admin')
                    <p class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Administration</p>
                    <a href="{{ route('administration.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Currently Open Year
                    </a>
                @endrole
                <div class="border-t border-gray-100 my-1"></div>
                <a href="{{ route('profile.edit') }}"
                    class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                    Profile
                </a>
                <button type="button" onclick="window.dispatchEvent(new CustomEvent('toggle-widget-edit'))"
                    class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                    Customize Dashboard
                </button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Log Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
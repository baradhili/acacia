<!-- Top Bar -->
<header class="sticky top-0 z-30 h-16 bg-white shadow-sm border-b border-gray-200 flex items-center justify-between px-6 shrink-0">
    <div>
        <h1 class="text-xl font-semibold text-gray-800">@yield('title', 'Dashboard')</h1>
    </div>
    <div class="flex items-center gap-2">
        {{-- Reports dropdown --}}
        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open"
                class="flex items-center gap-1 px-3 py-2 text-sm font-medium rounded-md transition-colors
                    {{ request()->routeIs('reports.*', 'bas-settlements.*') ? 'text-indigo-600 bg-indigo-50' : 'text-gray-700 hover:text-gray-900 hover:bg-gray-100' }}">
                Reports
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div x-show="open" @click.away="open = false"
                class="absolute right-0 mt-2 w-60 bg-white rounded-md shadow-lg py-1 z-50 max-h-[75vh] overflow-y-auto">
                <p class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Time &amp; Projects</p>
                <a href="{{ route('reports.time-by-client') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.time-by-client') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Time by Client
                </a>
                <a href="{{ route('reports.time-by-staff') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.time-by-staff') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Time by Staff
                </a>
                <a href="{{ route('reports.time-by-project') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.time-by-project') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Time by Project
                </a>
                <a href="{{ route('reports.project-timesheet') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.project-timesheet') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Project Timesheet
                </a>
                <a href="{{ route('projects.profitability') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('projects.profitability') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Project Profitability
                </a>

                <div class="border-t border-gray-100 my-1"></div>
                <p class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">IFRS Reports</p>
                <a href="{{ route('reports.balance-sheet') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.balance-sheet') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Balance Sheet
                </a>
                <a href="{{ route('reports.trial-balance') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.trial-balance') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Trial Balance
                </a>
                <a href="{{ route('reports.income-statement') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.income-statement') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Income Statement
                </a>
                <a href="{{ route('reports.cash-flow') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.cash-flow') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Cash Flow
                </a>
                <a href="{{ route('reports.account-statement') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.account-statement') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Account Statement
                </a>
                <a href="{{ route('reports.account-schedule') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.account-schedule') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Account Schedule
                </a>
                <a href="{{ route('reports.bas') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.bas') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    BAS (GST)
                </a>
                @hasanyrole('admin|accountant')
                    <a href="{{ route('bas-settlements.index') }}"
                        class="block px-4 py-2 text-sm {{ request()->routeIs('bas-settlements.*') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                        BAS Settlements
                    </a>
                @endhasanyrole
                <a href="{{ route('reports.company-tax') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.company-tax') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Company Tax Return
                </a>
                <a href="{{ route('reports.prepayment-schedule') }}"
                    class="block px-4 py-2 text-sm {{ request()->routeIs('reports.prepayment-schedule') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                    Prepayment Schedule
                </a>
            </div>
        </div>

        @hasanyrole('admin|accountant')
            {{-- Accounting dropdown --}}
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open"
                    class="flex items-center gap-1 px-3 py-2 text-sm font-medium rounded-md transition-colors
                        {{ request()->routeIs('prepayments.*', 'domains.*') ? 'text-indigo-600 bg-indigo-50' : 'text-gray-700 hover:text-gray-900 hover:bg-gray-100' }}">
                    Accounting
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div x-show="open" @click.away="open = false"
                    class="absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg py-1 z-50">
                    <a href="{{ route('prepayments.index') }}"
                        class="block px-4 py-2 text-sm {{ request()->routeIs('prepayments.*') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                        Prepayments
                    </a>
                    <a href="{{ route('domains.index') }}"
                        class="block px-4 py-2 text-sm {{ request()->routeIs('domains.*') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                        Domain Names
                    </a>
                </div>
            </div>

            {{-- Shares dropdown --}}
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open"
                    class="flex items-center gap-1 px-3 py-2 text-sm font-medium rounded-md transition-colors
                        {{ request()->routeIs('shareholders.*', 'franking-account.*', 'dividends.*', 'share-classes.*') ? 'text-indigo-600 bg-indigo-50' : 'text-gray-700 hover:text-gray-900 hover:bg-gray-100' }}">
                    Shares
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div x-show="open" @click.away="open = false"
                    class="absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg py-1 z-50">
                    <a href="{{ route('shareholders.index') }}"
                        class="block px-4 py-2 text-sm {{ request()->routeIs('shareholders.*') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                        Shareholders
                    </a>
                    <a href="{{ route('franking-account.index') }}"
                        class="block px-4 py-2 text-sm {{ request()->routeIs('franking-account.*') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                        Franking Account
                    </a>
                    <a href="{{ route('dividends.index') }}"
                        class="block px-4 py-2 text-sm {{ request()->routeIs('dividends.*') ? 'text-indigo-600 font-medium' : 'text-gray-700 hover:bg-gray-100' }}">
                        Dividends
                    </a>
                </div>
            </div>
        @endhasanyrole

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
                    <p class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Admin</p>
                    <a href="{{ route('administration.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Administration
                    </a>
                    <a href="{{ route('backups.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Backups
                    </a>
                    <a href="{{ route('users.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Users
                    </a>
                @endrole
                @hasanyrole('admin|accountant')
                    <p class="px-4 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Setup &amp; maintenance</p>
                    <a href="{{ route('company-profile.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Company Details
                    </a>
                    <a href="{{ route('chart-of-accounts.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Chart of Accounts
                    </a>
                    <a href="{{ route('opening-balances.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Opening Balances
                    </a>
                    <a href="{{ route('financial-years.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Financial Years
                    </a>
                    <a href="{{ route('services.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Services
                    </a>
                    <a href="{{ route('share-classes.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Share Classes
                    </a>
                    <a href="{{ route('psi.index') }}"
                        class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        PSI Assessment
                    </a>
                @endhasanyrole
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

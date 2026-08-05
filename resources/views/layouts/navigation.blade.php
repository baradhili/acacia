<!-- Sidebar -->
<aside class="fixed inset-y-0 left-0 w-64 bg-slate-800 text-white z-40">
    <!-- Logo -->
    <div class="h-16 flex items-center px-6 border-b border-slate-700">
        <a href="{{ route('dashboard') }}" class="text-xl font-bold">
            Laravel ERP
        </a>
    </div>

    <!-- Navigation -->
    <nav class="mt-6 px-3 overflow-y-auto" style="max-height: calc(100vh - 6rem);">
        <!-- Dashboard -->
        <a href="{{ route('dashboard') }}" 
           class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('dashboard') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            Dashboard
        </a>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Contacts -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Contacts</p>

        <!-- Clients -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('clients.index') }}" 
               class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('clients.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0M7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                Clients
            </a>
            <a href="{{ route('clients.create') }}" class="text-slate-400 hover:text-white px-2 text-lg font-bold opacity-0 group-hover:opacity-100 transition-opacity" title="Add Client">+</a>
        </div>

        <!-- Suppliers -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('suppliers.index') }}" 
               class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('suppliers.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
                Suppliers
            </a>
            <a href="{{ route('suppliers.create') }}" class="text-slate-400 hover:text-white px-2 text-lg font-bold opacity-0 group-hover:opacity-100 transition-opacity" title="Add Supplier">+</a>
        </div>

        <!-- Vendors -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('vendors.index') }}" 
               class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('vendors.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
                Vendors
            </a>
            <a href="{{ route('vendors.create') }}" class="text-slate-400 hover:text-white px-2 text-lg font-bold opacity-0 group-hover:opacity-100 transition-opacity" title="Add Vendor">+</a>
        </div>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Time & Projects -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Time & Projects</p>

        <!-- Projects -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('projects.index') }}" 
               class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('projects.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Projects
            </a>
            <a href="{{ route('projects.create') }}" class="text-slate-400 hover:text-white px-2 text-lg font-bold opacity-0 group-hover:opacity-100 transition-opacity" title="Add Project">+</a>
        </div>

        <!-- Time Entries -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('time-entries.index') }}" 
               class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('time-entries.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Time Entries
            </a>
            <a href="{{ route('time-entries.create') }}" class="text-slate-400 hover:text-white px-2 text-lg font-bold opacity-0 group-hover:opacity-100 transition-opacity" title="Add Time Entry">+</a>
        </div>

        <!-- My Timesheet -->
        <a href="{{ route('timesheets.weekly') }}" 
           class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('timesheets.weekly') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            My Timesheet
        </a>

        <!-- Purchase Orders -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('purchase-orders.index') }}" 
               class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('purchase-orders.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Purchase Orders
            </a>
            <a href="{{ route('purchase-orders.create') }}" class="text-slate-400 hover:text-white px-2 text-lg font-bold opacity-0 group-hover:opacity-100 transition-opacity" title="Add Purchase Order">+</a>
        </div>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Invoicing -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Invoicing</p>

        <a href="#" 
           class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors text-slate-300 hover:bg-slate-700 hover:text-white">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"></path>
            </svg>
            Invoices
            <span class="ml-auto text-xs bg-yellow-500 text-yellow-900 px-2 py-0.5 rounded">Soon</span>
        </a>

        <a href="#" 
           class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors text-slate-300 hover:bg-slate-700 hover:text-white">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
            </svg>
            Payments
            <span class="ml-auto text-xs bg-yellow-500 text-yellow-900 px-2 py-0.5 rounded">Soon</span>
        </a>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Banking -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Banking</p>

        <a href="{{ route('reconciliation.index') }}" 
           class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reconciliation.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
            </svg>
            Bank Reconciliation
        </a>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Accounting -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Accounting</p>

        <a href="#" 
           class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors text-slate-300 hover:bg-slate-700 hover:text-white">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
            </svg>
            Chart of Accounts
        </a>

        <!-- Reports submenu -->
        <div x-data="{ open: false }">
            <button @click="open = !open" class="w-full flex items-center px-3 py-2 mb-1 rounded-lg transition-colors text-slate-300 hover:bg-slate-700 hover:text-white">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Reports
                <svg class="w-4 h-4 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div x-show="open" class="pl-6 space-y-1">
                <a href="{{ route('reports.time-by-client') }}" 
                   class="flex items-center px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('reports.time-by-client') ? 'bg-slate-700 text-white' : 'text-slate-400 hover:bg-slate-700 hover:text-white' }}">
                    Time by Client
                </a>
                <a href="{{ route('reports.time-by-staff') }}" 
                   class="flex items-center px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('reports.time-by-staff') ? 'bg-slate-700 text-white' : 'text-slate-400 hover:bg-slate-700 hover:text-white' }}">
                    Time by Staff
                </a>
                <a href="{{ route('reports.time-by-project') }}" 
                   class="flex items-center px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('reports.time-by-project') ? 'bg-slate-700 text-white' : 'text-slate-400 hover:bg-slate-700 hover:text-white' }}">
                    Time by Project
                </a>
                <a href="{{ route('projects.index') }}" 
                   class="flex items-center px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('projects.profitability') ? 'bg-slate-700 text-white' : 'text-slate-400 hover:bg-slate-700 hover:text-white' }}">
                    Project Profitability
                </a>
            </div>
        </div>
    </nav>
</aside>

<!-- Top Bar -->
<header class="fixed top-0 left-64 right-0 h-16 bg-white shadow-sm border-b border-gray-200 flex items-center justify-between px-6 z-30">
    <div>
        <h1 class="text-xl font-semibold text-gray-800">@yield('title', 'Dashboard')</h1>
    </div>
    <div class="flex items-center gap-4">
        <!-- User Menu -->
        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" class="flex items-center gap-2 text-gray-700 hover:text-gray-900">
                <span>{{ Auth::user()->name }}</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </button>
            <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        Log Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

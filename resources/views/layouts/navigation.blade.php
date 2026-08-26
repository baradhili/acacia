<!-- Sidebar -->
<aside class="w-64 bg-slate-800 text-white shrink-0 flex flex-col min-h-screen sticky top-0 self-start">
    <!-- Logo -->
    <div class="h-16 flex items-center px-6 border-b border-slate-700">
		<a href="{{ route('dashboard') }}" class="flex items-center gap-3">
			<img src="{{ asset('images/logo.svg') }}" alt="Logo" class="h-8 w-auto">
			<span class="text-xl font-bold">Laravel ERP</span>
		</a>
	</div>

    <!-- Navigation -->
    <nav class="mt-6 px-3 overflow-y-auto" style="max-height: calc(100vh - 6rem);">
        <!-- Dashboard -->
        <a href="{{ route('dashboard') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('dashboard') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6">
                </path>
            </svg>
            Dashboard
        </a>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Administration -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Administration</p>

        <!-- Users -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('users.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('users.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z">
                    </path>
                </svg>
                Users
            </a>
            <a href="{{ route('users.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Add User">+</a>
        </div>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Contacts -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Contacts</p>

        <!-- Clients -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('clients.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('clients.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0M7 10a2 2 0 11-4 0 2 2 0 014 0z">
                    </path>
                </svg>
                Clients
            </a>
            <a href="{{ route('clients.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Add Client">+</a>
        </div>

        <!-- Suppliers -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('suppliers.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('suppliers.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
                Suppliers
            </a>
            <a href="{{ route('suppliers.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Add Supplier">+</a>
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
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    </path>
                </svg>
                Projects
            </a>
            <a href="{{ route('projects.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Add Project">+</a>
        </div>

        <!-- Time Entries -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('time-entries.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('time-entries.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Time Entries
            </a>
            <a href="{{ route('time-entries.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Add Time Entry">+</a>
        </div>

        <!-- My Timesheet -->
        <a href="{{ route('timesheets.weekly') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('timesheets.weekly') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            My Timesheet
        </a>

        <!-- Purchase Orders -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('purchase-orders.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('purchase-orders.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    </path>
                </svg>
                Purchase Orders
            </a>
            <a href="{{ route('purchase-orders.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Add Purchase Order">+</a>
        </div>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Invoicing -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Invoicing</p>

        <!-- Invoices -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('invoices.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('invoices.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z">
                    </path>
                </svg>
                Invoices
            </a>
            <a href="{{ route('invoices.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="New Invoice">+</a>
        </div>

        <!-- Payments -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('payments.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('payments.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z">
                    </path>
                </svg>
                Payments
            </a>
            <a href="{{ route('payments.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Record Payment">+</a>
        </div>

        <!-- Estimates -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('estimates.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('estimates.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2">
                    </path>
                </svg>
                Estimates
            </a>
            <a href="{{ route('estimates.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="New Estimate">+</a>
        </div>

        <!-- Bills -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('bills.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('bills.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                    </path>
                </svg>
                Bills
            </a>
            <a href="{{ route('bills.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="New Bill">+</a>
        </div>

        <!-- Supplier Payments -->
        <div class="flex items-center justify-between group">
            <a href="{{ route('bill-payments.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors flex-1 {{ request()->routeIs('bill-payments.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z">
                    </path>
                </svg>
                Supplier Payments
            </a>
            <a href="{{ route('bill-payments.create') }}"
                class="text-slate-500 hover:text-white px-2 text-lg font-bold transition-colors"
                title="Record Supplier Payment">+</a>
        </div>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Banking -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Banking</p>

        <a href="{{ route('reconciliation.index') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reconciliation.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
            </svg>
            Bank Reconciliation
        </a>

        <!-- Divider -->
        <div class="my-4 border-t border-slate-700"></div>

        <!-- Accounting -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Accounting</p>

        <a href="{{ route('chart-of-accounts.index') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('chart-of-accounts.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                </path>
            </svg>
            Chart of Accounts
        </a>

        @hasanyrole('admin|accountant')
            <a href="{{ route('opening-balances.index') }}"
                class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('opening-balances.*') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3">
                    </path>
                </svg>
                Opening Balances
            </a>
        @endhasanyrole

        <!-- Reports -->
        <p class="px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Reports</p>
        <a href="{{ route('reports.time-by-client') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reports.time-by-client') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Time by Client
        </a>
        <a href="{{ route('reports.time-by-staff') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reports.time-by-staff') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Time by Staff
        </a>
        <a href="{{ route('reports.time-by-project') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reports.time-by-project') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Time by Project
        </a>
        <a href="{{ route('projects.index') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('projects.profitability') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Project Profitability
        </a>

        <div class="border-t border-slate-700 my-2"></div>
        <p class="px-3 py-1 text-xs font-semibold text-slate-500 uppercase">IFRS Reports</p>
        <a href="{{ route('reports.account-statement') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reports.account-statement') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Account Statement
        </a>
        <a href="{{ route('reports.account-schedule') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reports.account-schedule') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Account Schedule
        </a>
        <a href="{{ route('reports.tax-summary') }}"
            class="flex items-center px-3 py-2 mb-1 rounded-lg transition-colors {{ request()->routeIs('reports.tax-summary') ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                </path>
            </svg>
            Tax Summary
        </a>
    </nav>
</aside>


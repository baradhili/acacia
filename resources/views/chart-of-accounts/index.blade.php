@extends('layouts.app')
@section('title', 'Chart of Accounts')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Chart of Accounts</h1>
    </div>

    <!-- Filter -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <form method="GET" class="flex items-center gap-4">
            <label class="text-sm font-medium text-gray-700">Filter by Type:</label>
            <select name="type" onchange="this.form.submit()" 
                    class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="">All Account Types</option>
                @foreach($accountTypes as $type => $label)
                    <option value="{{ $type }}" {{ $selectedType === $type ? 'selected' : '' }}>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    <!-- Accounts by Type -->
    <div class="space-y-6">
        @forelse($groupedAccounts as $type => $accounts)
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-slate-700 text-white px-6 py-3">
                    <h2 class="text-lg font-semibold">
                        {{ $accountTypes[$type] ?? $type }}
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($accounts as $account)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">
                                        {{ $account->code ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $account->name }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        {{ $account->description ?? '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ $account->category->name ?? '-' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500">
                No accounts found.
            </div>
        @endforelse
    </div>
@endsection

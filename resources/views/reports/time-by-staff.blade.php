@extends('layouts.app')
@section('title', 'Time by Staff Report')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Time by Staff Report</h1>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form method="GET" class="flex gap-4 items-end">
            <div>
                <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                <input type="date" name="start_date" id="start_date" value="{{ $startDate->format('Y-m-d') }}"
                    class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                <input type="date" name="end_date" id="end_date" value="{{ $endDate->format('Y-m-d') }}"
                    class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label for="user_id" class="block text-sm font-medium text-gray-700">Staff Member</label>
                <select name="user_id" id="user_id"
                    class="mt-1 block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">All Staff</option>
                    @foreach($staff as $id => $name)
                        <option value="{{ $id }}" {{ $userId == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                Filter
            </button>
        </form>
    </div>

    <!-- Summary -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Total Hours</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($totalHours, 1) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Total Amount</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">${{ number_format($totalAmount, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Staff Members</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $byStaff->count() }}</p>
        </div>
    </div>

    <!-- Report Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff Member</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Hours</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Billable Hours</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Non-Billable</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Amount</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Entries</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($byStaff as $staff)
                    <tr>
                        <td class="px-6 py-4 text-gray-900">{{ $staff['user']->name }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ number_format($staff['total_hours'], 1) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ number_format($staff['billable_hours'], 1) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ number_format($staff['non_billable_hours'], 1) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($staff['total_amount'], 2) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ $staff['entry_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No data for selected period.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
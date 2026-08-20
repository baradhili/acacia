@extends('layouts.app')
@section('title', 'Project Profitability')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Project Profitability: {{ $project->name }}</h1>
        <a href="{{ route('projects.show', $project) }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
            Back to Project
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Billable Revenue</h3>
            <p class="mt-1 text-2xl font-bold text-green-600">${{ number_format($totalRevenue, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Staff Cost</h3>
            <p class="mt-1 text-2xl font-bold text-red-600">${{ number_format($totalCost, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Profit</h3>
            <p class="mt-1 text-2xl font-bold text-indigo-600">${{ number_format($profit, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Profit Margin</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($profitMargin, 1) }}%</p>
        </div>
    </div>

    <!-- Time Entries Breakdown -->
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Time Entries Breakdown</h3>
        
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Billable</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Rate</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($project->timeEntries as $entry)
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $entry->entry_date?->format('d M Y') ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $entry->user->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ Str::limit($entry->description, 40) }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($entry->billable)
                                <span class="text-green-600">✓</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format($entry->hours, 1) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">${{ number_format($entry->effective_rate, 2) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">${{ number_format($entry->total, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-3 text-center text-gray-500">No time entries found.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50">
                <tr>
                    <td colspan="4" class="px-4 py-3 text-right font-bold text-gray-900">Totals:</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-900">{{ number_format($project->timeEntries->sum('hours'), 1) }}</td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3 text-right font-bold text-gray-900">${{ number_format($project->timeEntries->sum('total'), 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

@endsection
@extends('layouts.app')
@section('title', 'Weekly Timesheet')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Weekly Timesheet</h1>
        <a href="{{ route('time-entries.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + New Entry
        </a>
    </div>

    <!-- Week Navigation -->
    <div class="bg-white rounded-lg shadow p-4 mb-6 flex justify-between items-center">
        <a href="{{ route('timesheets.weekly', ['week' => $weekStart->copy()->subWeek()->format('Y-m-d'), 'user_id' => $userId]) }}" 
           class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded-lg">← Previous Week</a>
        <h2 class="text-lg font-semibold">
            Week of {{ $weekStart->format('d M Y') }} - {{ $weekEnd->format('d M Y') }}
        </h2>
        <a href="{{ route('timesheets.weekly', ['week' => $weekStart->copy()->addWeek()->format('Y-m-d'), 'user_id' => $userId]) }}" 
           class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded-lg">Next Week →</a>
    </div>

    <!-- Timesheet Grid -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-32">Day</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase w-24">Hours</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @php
                    $currentDay = $weekStart->copy();
                    $weekTotal = 0;
                @endphp
                @while($currentDay <= $weekEnd)
                    @php
                        $dayEntries = $byDay->get($currentDay->format('Y-m-d'), collect());
                        $dayTotal = $dayEntries->sum('hours');
                        $weekTotal += $dayTotal;
                    @endphp
                    <tr class="{{ $currentDay->isToday() ? 'bg-blue-50' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $currentDay->format('l') }}</div>
                            <div class="text-sm text-gray-500">{{ $currentDay->format('d M') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @forelse($dayEntries as $entry)
                                <div class="text-sm text-gray-900 mb-1">{{ $entry->project?->name ?? $entry->client?->name ?? '-' }}</div>
                            @empty
                                <span class="text-gray-400">-</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-3">
                            @forelse($dayEntries as $entry)
                                <div class="text-sm text-gray-700 mb-1">{{ Str::limit($entry->description, 60) ?: '-' }}</div>
                            @empty
                                <span class="text-gray-400">-</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-3 text-right font-medium text-gray-900">
                            {{ number_format($dayTotal, 1) }}
                        </td>
                    </tr>
                    @php $currentDay->addDay(); @endphp
                @endwhile
            </tbody>
            <tfoot class="bg-gray-100">
                <tr>
                    <td colspan="3" class="px-4 py-3 text-right font-bold text-gray-900">Week Total</td>
                    <td class="px-4 py-3 text-right font-bold text-xl text-gray-900">{{ number_format($weekTotal, 1) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Detailed Entries -->
    <div class="mt-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Detailed Entries</h3>
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($timeEntries as $entry)
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                {{ $entry->entry_date?->format('d M Y') ?? '-' }}
                                <span class="text-gray-500">
                                    {{ $entry->start_time && $entry->end_time
                                        ? $entry->start_time->format('H:i') . '–' . $entry->end_time->format('H:i')
                                        : '(manual)' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $entry->project?->name ?? $entry->client?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ Str::limit($entry->description, 50) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format($entry->hours, 1) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    @if($entry->status === 'approved') bg-green-100 text-green-800
                                    @elseif($entry->status === 'submitted') bg-yellow-100 text-yellow-800
                                    @else bg-gray-100 text-gray-800 @endif">
                                    {{ ucfirst($entry->status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-3 text-center text-gray-500">No entries for this week.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

@endsection
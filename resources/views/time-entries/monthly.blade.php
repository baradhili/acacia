<x-app-layout title="Monthly Timesheet">
    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Monthly Timesheet</h1>
        <a href="{{ route('time-entries.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + New Entry
        </a>
    </div>

    <!-- Month Navigation -->
    <div class="bg-white rounded-lg shadow p-4 mb-6 flex justify-between items-center">
        <a href="{{ route('timesheets.monthly', ['month' => $month->copy()->subMonth()->format('Y-m'), 'user_id' => $userId]) }}" 
           class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded-lg">← Previous Month</a>
        <h2 class="text-lg font-semibold">
            {{ $month->format('F Y') }}
        </h2>
        <a href="{{ route('timesheets.monthly', ['month' => $month->copy()->addMonth()->format('Y-m'), 'user_id' => $userId]) }}" 
           class="bg-gray-200 hover:bg-gray-300 px-4 py-2 rounded-lg">Next Month →</a>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Total Hours</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($totalHours, 1) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Billable Hours</h3>
            <p class="mt-1 text-2xl font-bold text-green-600">{{ number_format($totalBillable, 1) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Non-Billable</h3>
            <p class="mt-1 text-2xl font-bold text-gray-600">{{ number_format($totalHours - $totalBillable, 1) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Billable %</h3>
            <p class="mt-1 text-2xl font-bold text-indigo-600">
                {{ $totalHours > 0 ? round(($totalBillable / $totalHours) * 100, 1) : 0 }}%
            </p>
        </div>
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
                    $currentDay = $month->copy();
                    $monthTotal = 0;
                @endphp
                @while($currentDay <= $monthEnd)
                    @php
                        $dayEntries = $byDay->get($currentDay->format('Y-m-d'), collect());
                        $dayTotal = $dayEntries->sum('hours');
                        $monthTotal += $dayTotal;
                    @endphp
                    <tr class="{{ $currentDay->isToday() ? 'bg-blue-50' : '' }} {{ $currentDay->isWeekend() ? 'bg-gray-50' : '' }}">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $currentDay->format('j') }}</div>
                            <div class="text-sm text-gray-500">{{ $currentDay->format('l') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @forelse($dayEntries as $entry)
                                <div class="text-sm text-gray-900 mb-1">{{ $entry->project?->name ?? '-' }}</div>
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
                    <td colspan="3" class="px-4 py-3 text-right font-bold text-gray-900">Month Total</td>
                    <td class="px-4 py-3 text-right font-bold text-xl text-gray-900">{{ number_format($monthTotal, 1) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-app-layout>

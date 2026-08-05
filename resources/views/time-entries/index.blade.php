@extends('layouts.app')
@section('title', 'Time Entries')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Time Entries</h1>
        <div class="flex gap-3">
            <a href="{{ route('timesheets.weekly') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                Weekly Timesheet
            </a>
            <a href="{{ route('timesheets.monthly') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                Monthly Timesheet
            </a>
            <a href="{{ route('time-entries.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                + New Entry
            </a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($timeEntries as $entry)
                    <tr>
                        <td class="px-6 py-4 text-gray-900">{{ $entry->start_time->format('d M Y') }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ $entry->user->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ $entry->project?->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-900">{{ Str::limit($entry->description, 40) }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ number_format($entry->hours, 1) }}</td>
                        <td class="px-6 py-4">
                            @php
                                $statusColors = [
                                    'draft' => 'bg-gray-100 text-gray-800',
                                    'submitted' => 'bg-yellow-100 text-yellow-800',
                                    'approved' => 'bg-green-100 text-green-800',
                                    'rejected' => 'bg-red-100 text-red-800',
                                ];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusColors[$entry->status] }}">
                                {{ ucfirst($entry->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('time-entries.show', $entry) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                            @if($entry->status === 'draft')
                                <a href="{{ route('time-entries.edit', $entry) }}" class="text-gray-600 hover:text-gray-900 mr-3">Edit</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">No time entries found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $timeEntries->links() }}
    </div>

@endsection
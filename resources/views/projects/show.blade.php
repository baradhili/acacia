@extends('layouts.app')
@section('title', $project->name)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $project->name }}</h1>
        <div class="flex gap-3">
            <a href="{{ route('projects.edit', $project) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                Edit Project
            </a>
            <a href="{{ route('projects.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                Back
            </a>
        </div>
    </div>

    <!-- Project Info -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Client</h3>
                <p class="mt-1 text-gray-900">{{ $project->client->name ?? '-' }}</p>
            </div>
            @if($project->purchaseOrder)
            <div>
                <h3 class="text-sm font-medium text-gray-500">Purchase Order</h3>
                <p class="mt-1 text-gray-900">
                    <a href="{{ route('purchase-orders.show', $project->purchaseOrder) }}" class="text-indigo-600 hover:text-indigo-800">
                        {{ $project->purchaseOrder->po_number }}
                    </a>
                </p>
            </div>
            @endif
            <div>
                <h3 class="text-sm font-medium text-gray-500">Status</h3>
                @php
                    $statusColors = [
                        'active' => 'bg-green-100 text-green-800',
                        'on_hold' => 'bg-yellow-100 text-yellow-800',
                        'completed' => 'bg-blue-100 text-blue-800',
                        'cancelled' => 'bg-red-100 text-red-800',
                    ];
                @endphp
                <span class="mt-1 inline-flex px-2 py-1 text-sm font-semibold rounded-full {{ $statusColors[$project->status] ?? 'bg-gray-100 text-gray-800' }}">
                    {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                </span>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Hourly Rate</h3>
                <p class="mt-1 text-gray-900">{{ $project->hourly_rate ? '$' . number_format($project->hourly_rate, 2) : '-' }}</p>
            </div>
        </div>

        @if($project->description)
            <div class="mt-6 pt-6 border-t">
                <h3 class="text-sm font-medium text-gray-500">Description</h3>
                <p class="mt-1 text-gray-900">{{ $project->description }}</p>
            </div>
        @endif
    </div>

    <!-- Budget Summary -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Budget Hours</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $project->budget_hours ? number_format($project->budget_hours, 1) : '-' }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Logged Hours</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($project->total_hours, 1) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Utilization</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">
                {{ $project->budget_hours ? number_format($project->budget_utilization, 1) . '%' : '-' }}
            </p>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-sm font-medium text-gray-500">Remaining Hours</h3>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($project->remaining_hours, 1) }}</p>
        </div>
    </div>

    <!-- Staff Assignments -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Staff Assignments</h3>
        </div>

        @if($project->staffAssignments->count() > 0)
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff Member</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hourly Rate</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective Rate</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($project->staffAssignments as $assignment)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $assignment->user->name }}</td>
                            <td class="px-4 py-3 text-gray-900">
                                {{ $assignment->hourly_rate ? '$' . number_format($assignment->hourly_rate, 2) : 'Project Default' }}
                            </td>
                            <td class="px-4 py-3 text-gray-900">${{ number_format($assignment->effective_rate, 2) }}</td>
                            <td class="px-4 py-3 text-right">
                                <form action="{{ route('projects.staff.remove', [$project, $assignment->user]) }}" method="POST" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('Remove staff member?')">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-gray-500 text-center py-4">No staff assigned to this project.</p>
        @endif

        <!-- Add Staff Form -->
        <div class="mt-6 pt-6 border-t">
            <h4 class="text-sm font-medium text-gray-700 mb-3">Assign Staff Member</h4>
            <form action="{{ route('projects.staff.assign', $project) }}" method="POST" class="flex gap-3">
                @csrf
                <select name="user_id" required class="flex-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Select Staff Member</option>
                    @foreach(\App\Models\User::role(['staff', 'accountant', 'admin'])->get() as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
                <input type="number" name="hourly_rate" placeholder="Hourly Rate (optional)" step="0.01" min="0"
                    class="w-40 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Assign
                </button>
            </form>
        </div>
    </div>

    <!-- Recent Time Entries -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Recent Time Entries</h3>
            <a href="#" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">View All</a>
        </div>

        @if($project->timeEntries->count() > 0)
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Hours</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($project->timeEntries->take(10) as $entry)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $entry->start_time->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ $entry->user->name }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ Str::limit($entry->description, 50) }}</td>
                            <td class="px-4 py-3 text-right text-gray-900">{{ number_format($entry->hours, 1) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    @if($entry->status === 'approved') bg-green-100 text-green-800
                                    @elseif($entry->status === 'submitted') bg-yellow-100 text-yellow-800
                                    @elseif($entry->status === 'rejected') bg-red-100 text-red-800
                                    @else bg-gray-100 text-gray-800 @endif">
                                    {{ ucfirst($entry->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-gray-500 text-center py-4">No time entries for this project.</p>
        @endif
    </div>

@endsection
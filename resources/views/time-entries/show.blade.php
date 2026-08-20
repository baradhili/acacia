@extends('layouts.app')
@section('title', 'Time Entry')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Time Entry</h1>
        <a href="{{ route('time-entries.index') }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
            Back
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Staff Member</h3>
                <p class="mt-1 text-gray-900">{{ $timeEntry->user->name ?? '-' }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Status</h3>
                @php
                    $statusColors = [
                        'draft' => 'bg-gray-100 text-gray-800',
                        'submitted' => 'bg-yellow-100 text-yellow-800',
                        'approved' => 'bg-green-100 text-green-800',
                        'rejected' => 'bg-red-100 text-red-800',
                    ];
                @endphp
                <span class="mt-1 inline-flex px-2 py-1 text-sm font-semibold rounded-full {{ $statusColors[$timeEntry->status] }}">
                    {{ ucfirst($timeEntry->status) }}
                </span>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Project</h3>
                <p class="mt-1 text-gray-900">{{ $timeEntry->project?->name ?? '-' }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Purchase Order</h3>
                <p class="mt-1 text-gray-900">{{ $timeEntry->purchaseOrder?->po_number ?? '-' }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Date</h3>
                <p class="mt-1 text-gray-900">{{ $timeEntry->entry_date?->format('d M Y') ?? '-' }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Times</h3>
                <p class="mt-1 text-gray-900">
                    @if ($timeEntry->start_time && $timeEntry->end_time)
                        {{ $timeEntry->start_time->format('H:i') }} – {{ $timeEntry->end_time->format('H:i') }}
                        @if ($timeEntry->breaks->isNotEmpty())
                            <span class="text-gray-500">(less {{ $timeEntry->breaks->sum(fn ($b) => $b->durationMinutes()) }} min of breaks)</span>
                        @endif
                    @else
                        <span class="text-gray-500">Manual hours</span>
                    @endif
                </p>
                @if ($timeEntry->breaks->isNotEmpty())
                    <ul class="mt-1 text-sm text-gray-600">
                        @foreach ($timeEntry->breaks as $break)
                            <li>Break: {{ $break->start_display }} – {{ $break->end_display }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Hours</h3>
                <p class="mt-1 text-gray-900">{{ number_format($timeEntry->hours, 2) }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Rate</h3>
                <p class="mt-1 text-gray-900">${{ number_format($timeEntry->effective_rate, 2) }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Total</h3>
                <p class="mt-1 text-xl font-bold text-gray-900">${{ number_format($timeEntry->total, 2) }}</p>
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-500">Billable</h3>
                <p class="mt-1 text-gray-900">{{ $timeEntry->billable ? 'Yes' : 'No' }}</p>
            </div>
        </div>

        @if($timeEntry->description)
            <div class="mt-6 pt-6 border-t">
                <h3 class="text-sm font-medium text-gray-500">Description</h3>
                <p class="mt-1 text-gray-900">{{ $timeEntry->description }}</p>
            </div>
        @endif

        @if($timeEntry->rejection_reason)
            <div class="mt-6 pt-6 border-t">
                <h3 class="text-sm font-medium text-red-600">Rejection Reason</h3>
                <p class="mt-1 text-gray-900">{{ $timeEntry->rejection_reason }}</p>
            </div>
        @endif
    </div>

    <!-- Actions -->
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Actions</h3>
        
        @if($timeEntry->status === 'draft')
            <div class="flex gap-3">
                <a href="{{ route('time-entries.edit', $timeEntry) }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Edit Entry
                </a>
                <form action="{{ route('time-entries.submit', $timeEntry) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        Submit for Approval
                    </button>
                </form>
                <form action="{{ route('time-entries.destroy', $timeEntry) }}" method="POST" class="inline" onsubmit="return confirm('Delete this entry?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Delete Entry
                    </button>
                </form>
            </div>
        @endif

        @if($timeEntry->status === 'submitted')
            <div class="flex gap-3">
                <form action="{{ route('time-entries.approve', $timeEntry) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Approve
                    </button>
                </form>
                <form action="{{ route('time-entries.reject', $timeEntry) }}" method="POST" class="inline">
                    @csrf
                    <div class="flex gap-2">
                        <input type="text" name="reason" placeholder="Rejection reason" required
                            class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                            Reject
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>

@endsection
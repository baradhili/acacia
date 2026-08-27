@extends('layouts.app')
@section('title', 'Financial Years')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Financial Years</h1>
        <p class="text-sm text-gray-500">
            Year-end close: trial → approval (requester ≠ approver) → closing entries to Retained Earnings, then the year locks
        </p>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    @if ($unclosedPriorYear)
        <div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4 flex justify-between items-center">
            <p class="text-sm text-amber-800">
                <strong>Action needed:</strong> FY {{ $unclosedPriorYear }} has ended but hasn't been closed.
                Its reporting is still correct, but running the year-end close moves the profit into Retained
                Earnings and locks the year against late postings.
            </p>
            <a href="{{ route('financial-years.trial', $unclosedPriorYear) }}"
                class="ml-4 shrink-0 px-3 py-1.5 bg-amber-600 text-white text-sm rounded hover:bg-amber-700">
                Run trial close
            </a>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Financial year</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Period</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Workflow</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach ($years as $fy)
                        <tr class="hover:bg-gray-50 {{ $unclosedPriorYear === $fy->year ? 'bg-amber-50/60' : '' }}">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                FY {{ $fy->year }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ $fy->start->format('d M Y') }} – {{ $fy->end->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($fy->closed)
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">Closed</span>
                                @elseif ($fy->ended)
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800">Ended — open</span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-blue-100 text-blue-800">Current</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                @if ($fy->record)
                                    {{ $fy->record->statusLabel() }}
                                    @if ($fy->record->requested_by)
                                        <span class="block text-xs text-gray-400">
                                            by {{ $fy->record->requester?->name ?? 'unknown' }}
                                            @if ($fy->record->approved_by)· approved by {{ $fy->record->approver?->name ?? 'unknown' }}@endif
                                        </span>
                                    @endif
                                @else
                                    <span class="text-xs text-gray-400">No close started</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if ($fy->ended && !$fy->closed)
                                    <a href="{{ route('financial-years.trial', $fy->year) }}"
                                        class="px-3 py-1.5 bg-indigo-600 text-white text-xs rounded hover:bg-indigo-700">
                                        {{ $fy->record?->status === \App\Models\FiscalYearClose::STATUS_PENDING_APPROVAL ? 'Review' : 'Trial close' }}
                                    </a>
                                @elseif ($fy->closed)
                                    <form method="POST" action="{{ route('financial-years.reopen', $fy->year) }}" class="inline"
                                        onsubmit="return confirm('Reopen FY {{ $fy->year }}? The closing entries are reversed and the year becomes editable again.');">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-xs rounded hover:bg-red-700">
                                            Reopen
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        Closing posts two journal entries dated the year end (reference FY-CLOSE-{year}) that transfer every P&amp;L
        balance to Retained Earnings (3200), marks the IFRS reporting period CLOSED and locks the year's periods.
        Reopening mirrors the entries back out. Reports stay correct in both states.
    </p>
@endsection

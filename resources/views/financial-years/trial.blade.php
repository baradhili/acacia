@extends('layouts.app')
@section('title', "FY {$trial['year']} Trial Close")
@section('content')

    @php $record = $trial['record']; @endphp

    <div class="mb-6 flex justify-between items-start flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                Trial Close — FY {{ $trial['year'] }}
                <span class="text-sm font-normal text-gray-500">
                    {{ $trial['start']->format('d M Y') }} – {{ $trial['end']->format('d M Y') }}
                </span>
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                Snapshot saved to workflow record #{{ $record->id }} ({{ $record->statusLabel() }}) ·
                <a href="{{ route('financial-years.index') }}" class="text-indigo-600 hover:text-indigo-800">back to financial years</a>
            </p>
        </div>
        <div class="flex gap-2">
            @if ($record->canSubmit())
                <form method="POST" action="{{ route('financial-years.submit', $trial['year']) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700">
                        Submit for approval
                    </button>
                </form>
            @endif

            @if ($record->canApprove())
                @if ($record->requested_by === auth()->id() && !$approvalRoutedToRequester)
                    <span class="px-4 py-2 text-sm text-gray-500 bg-gray-100 rounded">
                        Waiting for another accountant/admin to approve
                    </span>
                @else
                    @if ($record->requested_by === auth()->id())
                        <span class="px-4 py-2 text-sm text-amber-700 bg-amber-50 rounded">
                            You are the only accountant/admin — this approval is routed to you
                        </span>
                    @endif
                    <form method="POST" action="{{ route('financial-years.approve', $trial['year']) }}">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">
                            Approve
                        </button>
                    </form>
                @endif
            @endif

            @if ($record->canClose())
                <form method="POST" action="{{ route('financial-years.close', $trial['year']) }}"
                    onsubmit="return confirm('Close FY {{ $trial['year'] }}? Closing entries transfer the P&L balances below to Retained Earnings ({{ $trial['retained_earnings']['code'] }}) and the year locks. This can be undone by reopening.');">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                        Execute close
                    </button>
                </form>
            @endif

            @if ($record->isClosed())
                <span class="px-4 py-2 text-sm text-green-800 bg-green-100 rounded">Closed {{ optional($record->closed_at)->format('d M Y') }}</span>
            @endif
        </div>
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

    <h2 class="text-lg font-semibold text-gray-800 mb-3">Pre-close checklist</h2>
    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <table class="min-w-full divide-y divide-gray-200">
            <tbody class="divide-y divide-gray-200">
                @foreach ($trial['checklist'] as $item)
                    <tr>
                        <td class="px-4 py-3 w-10 text-center">
                            @if ($item['pass'])
                                <span class="text-green-600">✓</span>
                            @elseif ($item['blocking'])
                                <span class="text-red-600">✗</span>
                            @else
                                <span class="text-amber-500">!</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span class="font-medium text-gray-900">{{ $item['label'] }}</span>
                            @unless ($item['blocking'])<span class="ml-2 text-xs text-gray-400">informational</span>@endunless
                            <span class="block text-xs text-gray-500 mt-0.5">{{ $item['detail'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @unless ($trial['checklist_passes'])
        <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">
            Blocking checklist items failed — resolve them before submitting for approval.
        </div>
    @endunless

    <h2 class="text-lg font-semibold text-gray-800 mb-3">
        Proposed closing entries
        <span class="text-sm font-normal text-gray-500">
            to {{ $trial['retained_earnings']['code'] }} {{ $trial['retained_earnings']['name'] }}, dated {{ $trial['end']->format('d M Y') }}
        </span>
    </h2>
    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">FY movement</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Prior years</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Close</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($trial['lines'] as $line)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $line['code'] }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $line['name'] }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $line['type'] }}</td>
                            <td class="px-4 py-3 text-sm text-right {{ $line['balance'] < 0 ? 'text-green-700' : 'text-gray-900' }}">{{ number_format($line['balance'], 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-600">{{ number_format($line['fy_movement'], 2) }}</td>
                            <td class="px-4 py-3 text-sm text-right text-gray-600">{{ number_format($line['prior_years'], 2) }}</td>
                            <td class="px-4 py-3 text-sm text-center">
                                <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $line['close_side'] === 'debit' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' }}">
                                    {{ strtoupper($line['close_side']) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">{{ number_format($line['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6 text-sm text-gray-500">
                                No P&amp;L account balances to close — an empty year still closes cleanly.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-blue-50 rounded-lg p-6">
            <p class="text-sm font-medium text-blue-700">FY {{ $trial['year'] }} net profit</p>
            <p class="text-2xl font-bold text-blue-800 mt-1">${{ number_format($trial['fy_net_profit'], 2) }}</p>
        </div>
        <div class="bg-amber-50 rounded-lg p-6">
            <p class="text-sm font-medium text-amber-700">Prior-years catch-up</p>
            <p class="text-2xl font-bold text-amber-800 mt-1">${{ number_format($trial['prior_years_catch_up'], 2) }}</p>
            <p class="text-xs text-amber-600 mt-1">Profit from earlier years never closed out — swept into Retained Earnings by this close.</p>
        </div>
        <div class="bg-green-50 rounded-lg p-6">
            <p class="text-sm font-medium text-green-700">Net to Retained Earnings</p>
            <p class="text-2xl font-bold text-green-800 mt-1">${{ number_format($trial['net_to_retained_earnings'], 2) }}</p>
        </div>
    </div>

    <p class="text-xs text-gray-500">
        Executing the close posts two journal entries (reference FY-CLOSE-{{ $trial['year'] }}) dated
        {{ $trial['end']->format('d M Y') }}: revenue-side balances debited to zero and expense-side balances
        credited to zero, with the net difference landing in Retained Earnings. The closing position then becomes
        FY {{ $trial['year'] + 1 }}'s opening balances (reference FY-CLOSE-{{ $trial['year'] }}-OB) — no manual
        re-entry. Reports exclude FY-CLOSE entries from P&amp;L movement, so historical statements are unchanged.
    </p>
@endsection

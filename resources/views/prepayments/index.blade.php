@extends('layouts.app')
@section('title', 'Prepayments')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Prepayments — Subscriptions &amp; Licences</h1>
        <p class="text-sm text-gray-500">
            Schedules funded by paid prepaid bill lines; the runner expenses them monthly at each month-end
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-indigo-50 rounded-lg p-6">
            <p class="text-sm font-medium text-indigo-700">Total funded (ex GST)</p>
            <p class="text-2xl font-bold text-indigo-800 mt-1">${{ number_format($totals['funded'], 2) }}</p>
        </div>
        <div class="bg-green-50 rounded-lg p-6">
            <p class="text-sm font-medium text-green-700">Amortised to expense</p>
            <p class="text-2xl font-bold text-green-800 mt-1">${{ number_format($totals['amortised'], 2) }}</p>
        </div>
        <div class="bg-amber-50 rounded-lg p-6">
            <p class="text-sm font-medium text-amber-700">Remaining prepaid asset</p>
            <p class="text-2xl font-bold text-amber-800 mt-1">${{ number_format($totals['remaining'], 2) }}</p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service period</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Monthly</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amortised</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Remaining</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progress</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($prepayments as $prepayment)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('prepayments.show', $prepayment) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                                    {{ $prepayment->description }}
                                </a>
                                <span class="block text-xs text-gray-500">
                                    {{ $prepayment->billItem?->bill?->bill_number }} · paid {{ optional($prepayment->billPayment?->payment_date)->format('d/m/Y') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ $prepayment->service_start->format('d/m/Y') }} – {{ $prepayment->service_end->format('d/m/Y') }}
                                <span class="block text-xs text-gray-400">{{ $prepayment->periods }} month(s) → {{ $prepayment->expenseAccount?->code }} {{ $prepayment->expenseAccount?->name }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($prepayment->total_amount, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($prepayment->monthly_amount, 2) }}</td>
                            <td class="px-4 py-3 text-sm text-green-700 text-right">${{ number_format($prepayment->amortisedAmount(), 2) }}</td>
                            <td class="px-4 py-3 text-sm text-amber-700 text-right">${{ number_format($prepayment->remainingAmount(), 2) }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $done = $prepayment->amortisations()->count();
                                    $pct = $prepayment->periods > 0 ? (int) round($done / $prepayment->periods * 100) : 0;
                                @endphp
                                <div class="w-28 bg-gray-200 rounded-full h-2">
                                    <div class="bg-indigo-600 h-2 rounded-full" style="width: {{ min(100, $pct) }}%"></div>
                                </div>
                                <span class="text-xs text-gray-500">{{ $done }}/{{ $prepayment->periods }} months</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($prepayment->status === \App\Models\Prepayment::STATUS_ACTIVE)
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">Active</span>
                                @elseif ($prepayment->status === \App\Models\Prepayment::STATUS_COMPLETED)
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600">Completed</span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700">Void</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6 text-sm text-gray-500">
                                No prepayments yet — tick <em>Prepaid</em> on a bill line with a service period, then pay the bill.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        The runner posts monthly (Dr {{ config('subscriptions.subscription_expense_code') }} expense / Cr {{ config('subscriptions.prepaid_subscription_code') }} prepaid asset)
        via the scheduled <code>prepayments:amortise</code> command; the schedule report lives under Reports.
    </p>
@endsection

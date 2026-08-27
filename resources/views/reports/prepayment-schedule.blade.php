@extends('reports.layout')

@section('title', 'Prepayment Amortisation Schedule')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">Prepaid Subscriptions — Amortisation Schedule</h2>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            <div class="mb-6 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Schedules as at {{ now()->format('d/m/Y') }}</h3>
                    <p class="text-sm text-gray-600">Posted, reversed and planned monthly entries per prepaid contract</p>
                </div>
                <a href="{{ route('reports.export.prepayment-schedule.pdf') }}"
                    class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm">PDF</a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="bg-indigo-50 rounded-lg p-6">
                    <p class="text-sm font-medium text-indigo-700">Total funded (ex GST)</p>
                    <p class="text-2xl font-bold text-indigo-800 mt-1">${{ number_format($totals['funded'], 2) }}</p>
                </div>
                <div class="bg-green-50 rounded-lg p-6">
                    <p class="text-sm font-medium text-green-700">Amortised to expense</p>
                    <p class="text-2xl font-bold text-green-800 mt-1">${{ number_format($totals['amortised'], 2) }}</p>
                </div>
                <div class="bg-amber-50 rounded-lg p-6">
                    <p class="text-sm font-medium text-amber-700">Prepaid asset remaining</p>
                    <p class="text-2xl font-bold text-amber-800 mt-1">${{ number_format($totals['remaining'], 2) }}</p>
                </div>
            </div>

            @forelse ($prepayments as $prepayment)
                <div class="mb-8">
                    <div class="flex justify-between items-baseline mb-2">
                        <h4 class="text-md font-semibold text-gray-800">
                            {{ $prepayment->description }}
                            <span class="text-xs font-normal text-gray-500">
                                {{ $prepayment->billItem?->bill?->bill_number }} ·
                                {{ $prepayment->service_start->format('d/m/Y') }} – {{ $prepayment->service_end->format('d/m/Y') }} ·
                                Cr {{ $prepayment->assetAccount?->code }} → Dr {{ $prepayment->expenseAccount?->code }}
                            </span>
                        </h4>
                        <span class="text-sm {{ $prepayment->status === \App\Models\Prepayment::STATUS_VOID ? 'text-red-600' : 'text-gray-600' }}">
                            {{ ucfirst($prepayment->status) }} — funded ${{ number_format($prepayment->total_amount, 2) }},
                            remaining ${{ number_format($prepayment->remainingAmount(), 2) }}
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month end</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">State</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ledger entry</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                @foreach ($schedules[$prepayment->id] as $row)
                                    <tr class="{{ $row['reversed'] ? 'bg-red-50/50' : '' }}">
                                        <td class="px-4 py-2 text-sm text-gray-900">{{ $row['period_date']->format('M Y') }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-900 text-right">${{ number_format($row['amount'], 2) }}</td>
                                        <td class="px-4 py-2 text-sm">
                                            @if ($row['reversed'])
                                                <span class="text-red-600">Reversed</span>
                                            @elseif ($row['posted'])
                                                <span class="text-green-700">Posted</span>
                                            @else
                                                <span class="text-gray-400">Planned</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-sm text-gray-500">
                                            {{ $row['transaction_id'] ? 'JE #' . $row['transaction_id'] : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No prepayments recorded yet.</p>
            @endforelse

            <div class="mt-6 text-xs text-gray-500 space-y-1">
                <p>Monthly entries post at each month-end of the service period (Dr expense / Cr prepaid asset), spanning financial years as required.</p>
                <p>The remaining prepaid balance ties to the {{ config('subscriptions.prepaid_subscription_code') }} Prepaid Subscriptions account on the balance sheet.</p>
            </div>
        </div>
    </div>
@endsection

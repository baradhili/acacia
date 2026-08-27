@extends('layouts.app')
@section('title', 'Prepayment Schedule')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $prepayment->description }}</h1>
            <p class="text-sm text-gray-500">
                {{ $prepayment->billItem?->bill?->bill_number }} · paid {{ optional($prepayment->billPayment?->payment_date)->format('d/m/Y') }} ·
                service period {{ $prepayment->service_start->format('d/m/Y') }} – {{ $prepayment->service_end->format('d/m/Y') }}
            </p>
        </div>
        <div class="flex gap-2">
            @if ($prepayment->status === \App\Models\Prepayment::STATUS_ACTIVE)
                <form method="POST" action="{{ route('prepayments.run', $prepayment) }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">
                        Run amortisation to date
                    </button>
                </form>
                <form method="POST" action="{{ route('prepayments.void', $prepayment) }}"
                    onsubmit="return confirm('Void this schedule? Every posted month will be reversed in the ledger.');">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm">
                        Void schedule
                    </button>
                </form>
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

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Funded (ex GST)</p>
            <p class="text-xl font-bold text-gray-800 mt-1">${{ number_format($prepayment->total_amount, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Monthly amount</p>
            <p class="text-xl font-bold text-gray-800 mt-1">${{ number_format($prepayment->monthly_amount, 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Amortised</p>
            <p class="text-xl font-bold text-green-700 mt-1">${{ number_format($prepayment->amortisedAmount(), 2) }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-xs font-medium text-gray-500 uppercase">Remaining</p>
            <p class="text-xl font-bold text-amber-700 mt-1">${{ number_format($prepayment->remainingAmount(), 2) }}</p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 border-b">
            <h2 class="text-md font-semibold text-gray-800">Amortisation schedule</h2>
            <p class="text-xs text-gray-500">
                Dr {{ $prepayment->expenseAccount?->code }} {{ $prepayment->expenseAccount?->name }} /
                Cr {{ $prepayment->assetAccount?->code }} {{ $prepayment->assetAccount?->name }} — posted at each month-end
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month end</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">State</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ledger entry</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach ($schedule as $row)
                        <tr class="{{ $row['reversed'] ? 'bg-red-50/50' : '' }}">
                            <td class="px-4 py-3 text-sm text-gray-900">{{ $row['period_date']->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right">${{ number_format($row['amount'], 2) }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if ($row['reversed'])
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700">Reversed</span>
                                @elseif ($row['posted'])
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">Posted</span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-500">Planned</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                @if ($row['transaction_id'])
                                    JE #{{ $row['transaction_id'] }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($row['posted'] && !$row['reversed'])
                                    <form method="POST" action="{{ route('prepayments.amortisations.reverse', $row['entry']) }}"
                                        onsubmit="return confirm('Reverse this month with a mirrored ledger entry?');">
                                        @csrf
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm">Reverse</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-800">Total</td>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-800 text-right">
                            ${{ number_format(collect($schedule)->where('reversed', false)->sum('amount'), 2) }}
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        Reversals post a same-date mirrored entry (the month stays consumed). A mis-stated month should be reversed and the
        correcting amount posted via a manual journal.
    </p>
@endsection

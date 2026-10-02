@extends('reports.layout')

@section('title', 'GST/BAS Report')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">GST/BAS Report</h1>
            <p class="report-subtitle">For the period {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.gst') }}" class="report-filters">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="start_date" value="{{ $startDate->format('Y-m-d') }}"
                    class="rounded-md border-gray-300 shadow-xs">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="{{ $endDate->format('Y-m-d') }}"
                    class="rounded-md border-gray-300 shadow-xs">
            </div>
            <div class="flex items-end">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Generate
                </button>
            </div>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- GST Collected (1A) -->
            <div class="bg-green-50 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-green-800 mb-4">GST Collected (Output Tax)</h3>
                <table class="w-full">
                    <tr>
                        <td class="py-2">Total sales — cash receipts, incl. GST (G1)</td>
                        <td class="text-right">{{ (int) $totalReceipts }}</td>
                    </tr>
                    <tr class="border-t">
                        <td class="py-2 font-semibold">GST on sales (1A)</td>
                        <td class="text-right font-bold text-green-700">{{ (int) $gstCollected }}</td>
                    </tr>
                </table>
            </div>

            <!-- GST Paid (1B) -->
            <div class="bg-red-50 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-red-800 mb-4">GST Paid (Input Tax)</h3>
                <table class="w-full">
                    <tr>
                        <td class="py-2">Total payments, incl. GST</td>
                        <td class="text-right">{{ (int) $totalPayments }}</td>
                    </tr>
                    <tr class="border-t">
                        <td class="py-2 font-semibold">GST on purchases (1B)</td>
                        <td class="text-right font-bold text-red-700">{{ (int) $gstPaid }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Net GST -->
        <div class="mt-6 bg-indigo-50 rounded-lg p-6">
            <div class="flex justify-between items-center">
                <span class="text-xl font-bold text-indigo-800">Net GST Payable/Refundable</span>
                <span class="text-2xl font-bold {{ $netGst >= 0 ? 'text-green-700' : 'text-red-700' }}">
                    {{ (int) $netGst }}
                </span>
            </div>
            <p class="text-sm text-indigo-600 mt-2">
                @if($netGst > 0)
                    Informational net of the two labels above — 1A exceeds 1B, so GST is payable
                @elseif($netGst < 0)
                    Informational net of the two labels above — 1B exceeds 1A, so a refund is due
                @else
                    1A equals 1B — no GST payable or refundable
                @endif
            </p>
            @hasanyrole('admin|accountant')
                <a href="{{ route('bas-settlements.index') }}"
                    class="inline-block mt-2 text-sm text-indigo-700 underline hover:text-indigo-900">
                    Record the settlement →
                </a>
            @endhasanyrole
        </div>

        <!-- Unlodged position: the account balances awaiting settlement -->
        <div class="mt-6 bg-white border border-indigo-100 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                Unlodged GST position as at {{ $endDate->format('d/m/Y') }}
            </h3>
            <p class="text-sm text-gray-500 mb-4">
                Both sides of the balances sitting on the GST accounts awaiting settlement —
                what a BAS settlement would net as at this date.
            </p>
            <table class="w-full max-w-md">
                <tr>
                    <td class="py-2">GST payable (owed to the ATO)</td>
                    <td class="text-right">{{ (int) $unlodged['payable'] }}</td>
                </tr>
                <tr>
                    <td class="py-2">GST receivable (refund due)</td>
                    <td class="text-right">{{ (int) $unlodged['receivable'] }}</td>
                </tr>
                <tr class="border-t border-gray-200">
                    <td class="py-2 font-semibold">
                        Net {{ $unlodged['net'] >= 0 ? 'payable to the ATO' : 'refundable from the ATO' }}
                    </td>
                    <td class="text-right font-bold">{{ (int) abs($unlodged['net']) }}</td>
                </tr>
            </table>
        </div>

        <div class="mt-6 text-sm text-gray-500">
            <p><strong>Note:</strong> Amounts are whole dollars with the cents dropped (the
            ATO's round-down) — copy them straight into the BAS labels; no cents, separators
            or symbols to strip. The net line is informational: the ATO form asks for 1A and
            1B separately and nets them itself. Cash basis — GST is recognised when payments
            are received or made (the posted ledger legs), not when invoices or bills are
            issued. Unposted payments appear once backfilled (<code>ifrs:post-payments</code>).</p>
            <p class="mt-1">This is a simplified GST/BAS report. For actual BAS lodgement,
            please refer to ATO guidelines and ensure all transactions are correctly classified.</p>
        </div>
    </div>
@endsection

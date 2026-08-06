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
                    class="rounded-md border-gray-300 shadow-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="end_date" value="{{ $endDate->format('Y-m-d') }}"
                    class="rounded-md border-gray-300 shadow-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Generate
                </button>
            </div>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- GST Collected (G1) -->
            <div class="bg-green-50 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-green-800 mb-4">GST Collected (Output Tax)</h3>
                <table class="w-full">
                    <tr>
                        <td class="py-2">Total Sales (ex. GST)</td>
                        <td class="text-right">${{ number_format($totalInvoices, 2) }}</td>
                    </tr>
                    <tr class="border-t">
                        <td class="py-2 font-semibold">GST on Sales (G1)</td>
                        <td class="text-right font-bold text-green-700">${{ number_format($gstCollected, 2) }}</td>
                    </tr>
                </table>
            </div>

            <!-- GST Paid (G2) -->
            <div class="bg-red-50 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-red-800 mb-4">GST Paid (Input Tax)</h3>
                <table class="w-full">
                    <tr>
                        <td class="py-2">Total Purchases (ex. GST)</td>
                        <td class="text-right">${{ number_format($totalExpenses, 2) }}</td>
                    </tr>
                    <tr class="border-t">
                        <td class="py-2 font-semibold">GST on Purchases (G2)</td>
                        <td class="text-right font-bold text-red-700">${{ number_format($gstPaid, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Net GST -->
        <div class="mt-6 bg-indigo-50 rounded-lg p-6">
            <div class="flex justify-between items-center">
                <span class="text-xl font-bold text-indigo-800">Net GST Payable/Refundable</span>
                <span class="text-2xl font-bold {{ $netGst >= 0 ? 'text-green-700' : 'text-red-700' }}">
                    ${{ number_format($netGst, 2) }}
                </span>
            </div>
            <p class="text-sm text-indigo-600 mt-2">
                @if($netGst > 0)
                    You need to pay GST to the ATO
                @elseif($netGst < 0)
                    You are entitled to a GST refund from the ATO
                @else
                    No GST payable or refundable
                @endif
            </p>
        </div>

        <div class="mt-6 text-sm text-gray-500">
            <p><strong>Note:</strong> This is a simplified GST/BAS report. For actual BAS lodgement, 
            please refer to ATO guidelines and ensure all transactions are correctly classified.</p>
        </div>
    </div>
@endsection

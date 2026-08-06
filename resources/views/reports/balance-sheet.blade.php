@extends('reports.layout')

@section('title', 'Balance Sheet')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Balance Sheet</h1>
            <p class="report-subtitle">As at {{ $endDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.balance-sheet') }}" class="report-filters">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">As at Date</label>
                <input type="date" name="end_date" value="{{ $endDate->format('Y-m-d') }}"
                    class="rounded-md border-gray-300 shadow-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Generate
                </button>
            </div>
        </form>

        @if(isset($lines['statement']))
        <div class="space-y-6">
            <!-- Assets -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Assets</h3>
                @if(isset($lines['statement']['assets']))
                <table class="report-table">
                    <tbody>
                        @foreach($lines['statement']['assets'] as $item)
                            <tr>
                                <td>{{ $item['account']['name'] ?? 'Asset' }}</td>
                                <td class="text-right">${{ number_format($item['balance'] ?? 0, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Total Assets</td>
                            <td class="text-right font-bold">${{ number_format($lines['statement']['assetsTotal'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
                @else
                    <p class="text-gray-500 italic">No assets recorded</p>
                @endif
            </div>

            <!-- Liabilities -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Liabilities</h3>
                @if(isset($lines['statement']['liabilities']))
                <table class="report-table">
                    <tbody>
                        @foreach($lines['statement']['liabilities'] as $item)
                            <tr>
                                <td>{{ $item['account']['name'] ?? 'Liability' }}</td>
                                <td class="text-right">(${{ number_format(abs($item['balance'] ?? 0), 2) }})</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Total Liabilities</td>
                            <td class="text-right font-bold">(${{ number_format(abs($lines['statement']['liabilitiesTotal'] ?? 0), 2) }})</td>
                        </tr>
                    </tfoot>
                </table>
                @else
                    <p class="text-gray-500 italic">No liabilities recorded</p>
                @endif
            </div>

            <!-- Equity -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Equity</h3>
                @if(isset($lines['statement']['equity']))
                <table class="report-table">
                    <tbody>
                        @foreach($lines['statement']['equity'] as $item)
                            <tr>
                                <td>{{ $item['account']['name'] ?? 'Equity' }}</td>
                                <td class="text-right">${{ number_format($item['balance'] ?? 0, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Total Equity</td>
                            <td class="text-right font-bold">${{ number_format($lines['statement']['equityTotal'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
                @else
                    <p class="text-gray-500 italic">No equity recorded</p>
                @endif
            </div>

            <!-- Balance Check -->
            <div class="border-t-2 border-gray-300 pt-4">
                <div class="flex justify-between items-center">
                    <span class="text-xl font-bold text-gray-800">Liabilities + Equity</span>
                    <span class="text-xl font-bold text-green-600">${{ number_format(($lines['statement']['liabilitiesTotal'] ?? 0) + ($lines['statement']['equityTotal'] ?? 0), 2) }}</span>
                </div>
                <div class="flex justify-between items-center mt-2">
                    <span class="text-xl font-bold text-gray-800">Balance Check</span>
                    <span class="text-xl font-bold {{ abs(($lines['statement']['assetsTotal'] ?? 0) - (($lines['statement']['liabilitiesTotal'] ?? 0) + ($lines['statement']['equityTotal'] ?? 0))) < 0.01 ? 'text-green-600' : 'text-red-600' }}">
                        {{ abs(($lines['statement']['assetsTotal'] ?? 0) - (($lines['statement']['liabilitiesTotal'] ?? 0) + ($lines['statement']['equityTotal'] ?? 0))) < 0.01 ? 'Balanced ✓' : 'Not Balanced' }}
                    </span>
                </div>
            </div>
        </div>
        @else
            <p class="text-gray-500 italic">No financial data available</p>
        @endif
    </div>
@endsection

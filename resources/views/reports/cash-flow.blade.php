@extends('reports.layout')

@section('title', 'Cash Flow Statement')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Cash Flow Statement</h1>
            <p class="report-subtitle">For the period {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.cash-flow') }}" class="report-filters">
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

        @if(isset($lines['statement']))
        <div class="space-y-6">
            <!-- Operating Activities -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Operating Activities</h3>
                <table class="report-table">
                    <tbody>
                        @if(isset($lines['statement']['operating']))
                            @foreach($lines['statement']['operating'] as $item)
                                <tr>
                                    <td>{{ $item['account']['name'] ?? 'Operating Activity' }}</td>
                                    <td class="text-right">${{ number_format($item['balance'] ?? 0, 2) }}</td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td class="text-gray-500 italic">No operating activities</td>
                                <td></td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Net Cash from Operating</td>
                            <td class="text-right font-bold">${{ number_format($lines['statement']['operatingTotal'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Investing Activities -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Investing Activities</h3>
                <table class="report-table">
                    <tbody>
                        @if(isset($lines['statement']['investing']))
                            @foreach($lines['statement']['investing'] as $item)
                                <tr>
                                    <td>{{ $item['account']['name'] ?? 'Investing Activity' }}</td>
                                    <td class="text-right">${{ number_format($item['balance'] ?? 0, 2) }}</td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td class="text-gray-500 italic">No investing activities</td>
                                <td></td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Net Cash from Investing</td>
                            <td class="text-right font-bold">${{ number_format($lines['statement']['investingTotal'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Financing Activities -->
            <div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Financing Activities</h3>
                <table class="report-table">
                    <tbody>
                        @if(isset($lines['statement']['financing']))
                            @foreach($lines['statement']['financing'] as $item)
                                <tr>
                                    <td>{{ $item['account']['name'] ?? 'Financing Activity' }}</td>
                                    <td class="text-right">${{ number_format($item['balance'] ?? 0, 2) }}</td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td class="text-gray-500 italic">No financing activities</td>
                                <td></td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="font-bold">Net Cash from Financing</td>
                            <td class="text-right font-bold">${{ number_format($lines['statement']['financingTotal'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Net Change -->
            <div class="border-t-2 border-gray-300 pt-4">
                <div class="flex justify-between items-center">
                    <span class="text-xl font-bold text-gray-800">Net Cash Movement</span>
                    <span class="text-xl font-bold {{ ($lines['statement']['netCash'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        ${{ number_format($lines['statement']['netCash'] ?? 0, 2) }}
                    </span>
                </div>
            </div>
        </div>
        @else
            <p class="text-gray-500 italic">No cash flow data available for this period</p>
        @endif
    </div>
@endsection

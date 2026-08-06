@extends('reports.layout')

@section('title', 'Aging Report')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Aging Schedule</h1>
            <p class="report-subtitle">As at {{ $asOfDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.aging') }}" class="report-filters">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">As at Date</label>
                <input type="date" name="as_of_date" value="{{ $asOfDate->format('Y-m-d') }}"
                    class="rounded-md border-gray-300 shadow-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="type" class="rounded-md border-gray-300 shadow-sm">
                    <option value="ar" {{ $type == 'ar' ? 'selected' : '' }}>Accounts Receivable</option>
                    <option value="ap" {{ $type == 'ap' ? 'selected' : '' }}>Accounts Payable</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Generate
                </button>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th class="text-right">Current</th>
                        <th class="text-right">1-30 Days</th>
                        <th class="text-right">31-60 Days</th>
                        <th class="text-right">61-90 Days</th>
                        <th class="text-right">Over 90 Days</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $clientBuckets = [];
                        foreach ($buckets as $bucket) {
                            foreach ($bucket['invoices'] as $item) {
                                $clientId = $item['invoice']->client_id;
                                if (!isset($clientBuckets[$clientId])) {
                                    $clientBuckets[$clientId] = [
                                        'client' => $item['client'],
                                        'current' => 0,
                                        'days_1_30' => 0,
                                        'days_31_60' => 0,
                                        'days_61_90' => 0,
                                        'days_over_90' => 0,
                                    ];
                                }
                                
                                $days = $item['days_past_due'];
                                if ($days <= 0) {
                                    $clientBuckets[$clientId]['current'] += $item['amount'];
                                } elseif ($days <= 30) {
                                    $clientBuckets[$clientId]['days_1_30'] += $item['amount'];
                                } elseif ($days <= 60) {
                                    $clientBuckets[$clientId]['days_31_60'] += $item['amount'];
                                } elseif ($days <= 90) {
                                    $clientBuckets[$clientId]['days_61_90'] += $item['amount'];
                                } else {
                                    $clientBuckets[$clientId]['days_over_90'] += $item['amount'];
                                }
                            }
                        }
                    @endphp
                    
                    @forelse($clientBuckets as $data)
                        <tr>
                            <td>{{ $data['client']->name ?? 'Unknown' }}</td>
                            <td class="text-right">${{ number_format($data['current'], 2) }}</td>
                            <td class="text-right">${{ number_format($data['days_1_30'], 2) }}</td>
                            <td class="text-right">${{ number_format($data['days_31_60'], 2) }}</td>
                            <td class="text-right">${{ number_format($data['days_61_90'], 2) }}</td>
                            <td class="text-right">${{ number_format($data['days_over_90'], 2) }}</td>
                            <td class="text-right font-medium">${{ number_format($data['current'] + $data['days_1_30'] + $data['days_31_60'] + $data['days_61_90'] + $data['days_over_90'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-gray-500 py-4">No outstanding invoices</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td class="font-bold">Totals</td>
                        <td class="text-right font-bold">${{ number_format($buckets['current']['total'], 2) }}</td>
                        <td class="text-right font-bold">${{ number_format($buckets['days_1_30']['total'], 2) }}</td>
                        <td class="text-right font-bold">${{ number_format($buckets['days_31_60']['total'], 2) }}</td>
                        <td class="text-right font-bold">${{ number_format($buckets['days_61_90']['total'], 2) }}</td>
                        <td class="text-right font-bold">${{ number_format($buckets['days_over_90']['total'], 2) }}</td>
                        <td class="text-right font-bold">${{ number_format($grandTotal, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endsection

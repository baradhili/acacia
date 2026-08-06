@extends('reports.layout')

@section('title', 'Trial Balance')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Trial Balance</h1>
            <p class="report-subtitle">As at {{ $endDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.trial-balance') }}" class="report-filters">
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

        <!-- Report Table -->
        <table class="report-table">
            <thead>
                <tr>
                    <th>Account Code</th>
                    <th>Account Name</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                </tr>
            </thead>
            <tbody>
                @forelse($accountLines as $line)
                    <tr>
                        <td>{{ $line['code'] }}</td>
                        <td>{{ $line['name'] }}</td>
                        <td class="text-right">{{ $line['debit'] > 0 ? '$' . number_format($line['debit'], 2) : '' }}</td>
                        <td class="text-right">{{ $line['credit'] > 0 ? '$' . number_format($line['credit'], 2) : '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-gray-500 py-4">No account balances found</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="text-right font-bold">Totals</td>
                    <td class="text-right font-bold">${{ number_format($debitTotal, 2) }}</td>
                    <td class="text-right font-bold">${{ number_format($creditTotal, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
@endsection

@extends('reports.layout')

@section('title', 'Income by Customer')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Income by Customer</h1>
            <p class="report-subtitle">For the period {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.income-by-customer') }}" class="report-filters">
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
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" class="rounded-md border-gray-300 shadow-sm">
                    <option value="">All Clients</option>
                    @foreach($clients as $id => $name)
                        <option value="{{ $id }}" {{ $clientId == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Generate
                </button>
            </div>
        </form>

        <table class="report-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th class="text-right">Invoices</th>
                    <th class="text-right">Total Invoiced</th>
                    <th class="text-right">Total Paid</th>
                    <th class="text-right">Outstanding</th>
                </tr>
            </thead>
            <tbody>
                @forelse($byCustomer as $item)
                    <tr>
                        <td>{{ $item['client']->name ?? 'Unknown' }}</td>
                        <td class="text-right">{{ $item['invoice_count'] }}</td>
                        <td class="text-right">${{ number_format($item['total_invoiced'], 2) }}</td>
                        <td class="text-right text-green-600">${{ number_format($item['total_paid'], 2) }}</td>
                        <td class="text-right {{ $item['outstanding'] > 0 ? 'text-red-600' : '' }}">
                            ${{ number_format($item['outstanding'], 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-gray-500 py-4">No invoices found</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td class="font-bold">Totals</td>
                    <td class="text-right font-bold">{{ $byCustomer->sum('invoice_count') }}</td>
                    <td class="text-right font-bold">${{ number_format($totalInvoiced, 2) }}</td>
                    <td class="text-right font-bold text-green-600">${{ number_format($totalPaid, 2) }}</td>
                    <td class="text-right font-bold text-red-600">${{ number_format($totalOutstanding, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
@endsection

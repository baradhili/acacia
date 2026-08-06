@extends('reports.layout')

@section('title', 'Expenses by Category')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Expenses by Category</h1>
            <p class="report-subtitle">For the period {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}</p>
        </div>

        <!-- Filters -->
        <form method="GET" action="{{ route('reports.expenses-by-category') }}" class="report-filters">
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
                <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                <select name="category" class="rounded-md border-gray-300 shadow-sm">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}" {{ $category == $cat ? 'selected' : '' }}>
                            {{ ucwords(str_replace('_', ' ', $cat)) }}
                        </option>
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
                    <th>Category</th>
                    <th class="text-right">Count</th>
                    <th class="text-right">Amount (ex. GST)</th>
                    <th class="text-right">GST</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse($byCategory as $item)
                    <tr>
                        <td>{{ $item['category'] }}</td>
                        <td class="text-right">{{ $item['expense_count'] }}</td>
                        <td class="text-right">${{ number_format($item['total_amount'], 2) }}</td>
                        <td class="text-right">${{ number_format($item['total_tax'], 2) }}</td>
                        <td class="text-right font-medium">${{ number_format($item['total'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-gray-500 py-4">No expenses found</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td class="font-bold">Totals</td>
                    <td class="text-right font-bold">{{ $byCategory->sum('expense_count') }}</td>
                    <td class="text-right font-bold">${{ number_format($totalAmount, 2) }}</td>
                    <td class="text-right font-bold">${{ number_format($totalTax, 2) }}</td>
                    <td class="text-right font-bold">${{ number_format($total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
@endsection

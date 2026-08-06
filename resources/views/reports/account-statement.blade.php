@extends('reports.layout')

@section('title', 'Account Statement')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">IFRS Account Statement</h2>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            <!-- Filters -->
            <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="account_id" class="block text-sm font-medium text-gray-700 mb-1">Account</label>
                    <select name="account_id" id="account_id" required
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Account</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" {{ $accountId == $account->id ? 'selected' : '' }}>
                                {{ $account->code }} - {{ $account->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                    <input type="date" name="start_date" id="start_date" 
                        value="{{ $startDate->format('Y-m-d') }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                    <input type="date" name="end_date" id="end_date" 
                        value="{{ $endDate->format('Y-m-d') }}"
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                        Generate Report
                    </button>
                </div>
            </form>

            @if($statementData)
                <div class="border-t pt-6">
                    <!-- Report Header -->
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-800">
                            {{ $statementData['account']->code }} - {{ $statementData['account']->name }}
                        </h3>
                        <p class="text-sm text-gray-600">
                            Period: {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}
                        </p>
                    </div>

                    <!-- Summary -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-sm font-medium text-gray-500">Opening Balance</p>
                            <p class="text-lg font-semibold text-gray-800">
                                ${{ number_format($statementData['opening_balance'], 2) }}
                            </p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-sm font-medium text-gray-500">Total Debit</p>
                            <p class="text-lg font-semibold text-red-600">
                                ${{ number_format($statementData['total_debit'], 2) }}
                            </p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-sm font-medium text-gray-500">Total Credit</p>
                            <p class="text-lg font-semibold text-green-600">
                                ${{ number_format($statementData['total_credit'], 2) }}
                            </p>
                        </div>
                        <div class="bg-indigo-50 rounded-lg p-4">
                            <p class="text-sm font-medium text-indigo-600">Closing Balance</p>
                            <p class="text-lg font-semibold text-indigo-800">
                                ${{ number_format($statementData['closing_balance'], 2) }}
                            </p>
                        </div>
                    </div>

                    <!-- Transactions Table -->
                    @if($statementData['transactions']->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ref</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Debit</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Credit</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr class="bg-gray-50">
                                        <td class="px-4 py-2 text-sm text-gray-600">{{ $startDate->format('d/m/Y') }}</td>
                                        <td colspan="2" class="px-4 py-2 text-sm text-gray-600 italic">Opening Balance</td>
                                        <td class="px-4 py-2 text-sm text-gray-600"></td>
                                        <td class="px-4 py-2 text-sm text-gray-600"></td>
                                        <td class="px-4 py-2 text-sm text-gray-900 text-right font-medium">
                                            ${{ number_format($statementData['opening_balance'], 2) }}
                                        </td>
                                    </tr>
                                    @foreach($statementData['transactions'] as $txn)
                                        <tr>
                                            <td class="px-4 py-2 text-sm text-gray-900">{{ $txn['date']->format('d/m/Y') }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-600">{{ $txn['reference'] ?? '-' }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-900">
                                                <span class="text-xs text-gray-500">{{ $txn['transaction_type'] }}</span>
                                                {{ $txn['narration'] }}
                                            </td>
                                            <td class="px-4 py-2 text-sm text-red-600 text-right">
                                                {{ $txn['debit'] ? '$' . number_format($txn['debit'], 2) : '-' }}
                                            </td>
                                            <td class="px-4 py-2 text-sm text-green-600 text-right">
                                                {{ $txn['credit'] ? '$' . number_format($txn['credit'], 2) : '-' }}
                                            </td>
                                            <td class="px-4 py-2 text-sm text-gray-900 text-right font-medium">
                                                ${{ number_format($txn['balance'], 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td colspan="3" class="px-4 py-3 text-sm font-semibold text-gray-800">Totals</td>
                                        <td class="px-4 py-3 text-sm font-semibold text-red-600 text-right">
                                            ${{ number_format($statementData['total_debit'], 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-sm font-semibold text-green-600 text-right">
                                            ${{ number_format($statementData['total_credit'], 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-sm font-semibold text-indigo-800 text-right">
                                            ${{ number_format($statementData['closing_balance'], 2) }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @else
                        <p class="text-center text-gray-500 py-8">No transactions found for this period.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection

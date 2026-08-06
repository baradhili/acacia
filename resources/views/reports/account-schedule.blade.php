@extends('reports.layout')

@section('title', 'Account Schedule')

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">IFRS Account Schedule</h2>
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

            @if($scheduleData)
                <div class="border-t pt-6">
                    <!-- Report Header -->
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-800">
                            {{ $scheduleData['account']->code }} - {{ $scheduleData['account']->name }}
                        </h3>
                        <p class="text-sm text-gray-600">
                            Period: {{ $startDate->format('d/m/Y') }} to {{ $endDate->format('d/m/Y') }}
                        </p>
                        <p class="text-sm text-gray-600">
                            {{ $scheduleData['line_count'] }} transaction(s)
                        </p>
                    </div>

                    <!-- Summary -->
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-sm font-medium text-gray-500">Total Debit</p>
                            <p class="text-lg font-semibold text-red-600">
                                ${{ number_format($scheduleData['total_debit'], 2) }}
                            </p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-sm font-medium text-gray-500">Total Credit</p>
                            <p class="text-lg font-semibold text-green-600">
                                ${{ number_format($scheduleData['total_credit'], 2) }}
                            </p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p class="text-sm font-medium text-gray-500">Net Movement</p>
                            <p class="text-lg font-semibold text-indigo-600">
                                ${{ number_format($scheduleData['total_debit'] - $scheduleData['total_credit'], 2) }}
                            </p>
                        </div>
                    </div>

                    <!-- Schedule Lines -->
                    @if($scheduleData['lines']->count() > 0)
                        <div class="space-y-6">
                            @foreach($scheduleData['lines'] as $line)
                                <div class="border rounded-lg overflow-hidden">
                                    <div class="bg-gray-50 px-4 py-3 flex items-center justify-between">
                                        <div>
                                            <span class="font-medium text-gray-800">
                                                {{ $line['date']->format('d/m/Y') }}
                                            </span>
                                            <span class="ml-2 px-2 py-1 text-xs rounded bg-indigo-100 text-indigo-800">
                                                {{ $line['transaction_type'] }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <span class="text-red-600 font-medium">
                                                {{ $line['debit'] ? '$' . number_format($line['debit'], 2) : '' }}
                                            </span>
                                            <span class="text-green-600 font-medium">
                                                {{ $line['credit'] ? '$' . number_format($line['credit'], 2) : '' }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="p-4">
                                        <p class="text-sm text-gray-600 mb-2">
                                            @if($line['reference'])
                                                <span class="font-medium">Ref:</span> {{ $line['reference'] }}
                                            @endif
                                        </p>
                                        <p class="text-sm text-gray-800 mb-4">{{ $line['narration'] }}</p>
                                        
                                        <!-- Line Items -->
                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Debit</th>
                                                    <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Credit</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                @foreach($line['line_items'] as $item)
                                                    <tr>
                                                        <td class="px-3 py-2 text-gray-900">
                                                            {{ $item->account->code ?? 'N/A' }} - {{ $item->account->name ?? 'N/A' }}
                                                        </td>
                                                        <td class="px-3 py-2 text-red-600 text-right">
                                                            {{ $item->type == 'debit' ? '$' . number_format($item->amount, 2) : '-' }}
                                                        </td>
                                                        <td class="px-3 py-2 text-green-600 text-right">
                                                            {{ $item->type == 'credit' ? '$' . number_format($item->amount, 2) : '-' }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-center text-gray-500 py-8">No transactions found for this period.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection

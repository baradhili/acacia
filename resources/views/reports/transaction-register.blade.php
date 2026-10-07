@extends('reports.layout')

@section('title', __('reports.transaction_register.title'))

@section('header')
    <h2 class="text-xl font-semibold text-gray-800">{{ __('reports.transaction_register.title') }}</h2>
@endsection

@section('content')
    <div class="bg-white rounded-lg shadow">
        <div class="p-6">
            <!-- Filters: blank dates mean the whole ledger -->
            <form method="GET" class="mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">{{ __('reports.transaction_register.start_date') }}</label>
                    <input type="date" name="start_date" id="start_date"
                        value="{{ $startDate?->format('Y-m-d') }}"
                        class="block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">{{ __('reports.transaction_register.end_date') }}</label>
                    <input type="date" name="end_date" id="end_date"
                        value="{{ $endDate?->format('Y-m-d') }}"
                        class="block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                        {{ __('reports.transaction_register.filter') }}
                    </button>
                </div>
            </form>

            <div class="border-t pt-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                    <p class="text-sm text-gray-600">
                        @if ($startDate || $endDate)
                            {{ __('reports.transaction_register.period_range', [
                                'start' => $startDate?->format('d/m/Y') ?? '…',
                                'end' => $endDate?->format('d/m/Y') ?? '…',
                            ]) }}
                        @else
                            {{ __('reports.transaction_register.period_all') }}
                        @endif
                    </p>
                    {{-- Indigo, not a fresh palette: the built stylesheet only
                        carries classes it was compiled with, and emerald
                        would rebuild to an invisible white-on-white button. --}}
                    <a href="{{ route('reports.export.transaction-register.csv', array_filter([
                        'start_date' => $startDate?->format('Y-m-d'),
                        'end_date' => $endDate?->format('Y-m-d'),
                    ])) }}"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                        </svg>
                        {{ __('reports.transaction_register.export_csv') }}
                    </a>
                </div>

                <!-- Summary -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-sm font-medium text-gray-500">{{ __('reports.transaction_register.leg_count') }}</p>
                        <p class="text-lg font-semibold text-gray-900">{{ number_format($rows->count()) }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-sm font-medium text-gray-500">{{ __('reports.transaction_register.total_debit') }}</p>
                        <p class="text-lg font-semibold text-gray-900">${{ number_format($totalDebit, 2) }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <p class="text-sm font-medium text-gray-500">{{ __('reports.transaction_register.total_credit') }}</p>
                        <p class="text-lg font-semibold text-gray-900">${{ number_format($totalCredit, 2) }}</p>
                    </div>
                </div>

                @if ($rows->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>{{ __('reports.transaction_register.reference') }}</th>
                                    <th>{{ __('reports.transaction_register.date') }}</th>
                                    <th>{{ __('reports.transaction_register.type') }}</th>
                                    <th>{{ __('reports.transaction_register.account') }}</th>
                                    <th style="text-align: right">{{ __('reports.transaction_register.debit') }}</th>
                                    <th style="text-align: right">{{ __('reports.transaction_register.credit') }}</th>
                                    <th>{{ __('reports.transaction_register.narration') }}</th>
                                    <th>{{ __('reports.transaction_register.documents') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="font-medium text-gray-900">{{ $row['reference'] }}</td>
                                        <td>{{ $row['date']->format('d/m/Y') }}</td>
                                        <td>{{ $row['type'] }}</td>
                                        <td>{{ $row['account_code'] }} - {{ $row['account_name'] }}</td>
                                        <td style="text-align: right">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                                        <td style="text-align: right">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                                        <td class="max-w-md">{{ $row['narration'] }}</td>
                                        <td>
                                            @forelse ($row['documents'] as $document)
                                                <a href="{{ route('documents.download', $document) }}"
                                                    class="text-indigo-600 hover:text-indigo-900 hover:underline">{{ $document->name }}</a>@if (! $loop->last)
                                                    &nbsp;·&nbsp;
                                                @endif
                                            @empty
                                                —
                                            @endforelse
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4">{{ __('reports.transaction_register.leg_count') }}: {{ number_format($rows->count()) }}</td>
                                    <td style="text-align: right">${{ number_format($totalDebit, 2) }}</td>
                                    <td style="text-align: right">${{ number_format($totalCredit, 2) }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500">{{ __('reports.transaction_register.no_rows') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection

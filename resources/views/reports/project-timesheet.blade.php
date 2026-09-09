@extends('reports.layout')

@section('title', 'Project Timesheet')

@section('report-content')
    <div class="p-6">
        <div class="report-header">
            <h1 class="report-title">Project Timesheet</h1>
            <p class="report-subtitle">
                Hours by week and by month per project — {{ $startDate->format('d/m/Y') }} to
                {{ $endDate->format('d/m/Y') }} (weeks start Monday; approved entries only)
            </p>
        </div>

        <form method="GET" action="{{ route('reports.project-timesheet') }}" class="report-filters">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                <select name="client_id" class="rounded-md border-gray-300 shadow-sm">
                    <option value="">All clients</option>
                    @foreach ($clients as $id => $name)
                        <option value="{{ $id }}" {{ (string) $clientId === (string) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                <select name="project_id" class="rounded-md border-gray-300 shadow-sm">
                    <option value="">All projects</option>
                    @foreach ($projects as $id => $name)
                        <option value="{{ $id }}" {{ (string) $projectId === (string) $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
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

        @if ($byProject->isEmpty())
            <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                No approved project time entries in this period.
            </div>
        @else
            <div class="mb-4 bg-indigo-50 rounded-lg p-4 flex justify-between text-sm">
                <span class="font-semibold text-indigo-800">All projects: {{ number_format($totalHours, 2) }} hours</span>
                <span class="font-semibold text-indigo-800">${{ number_format($totalAmount, 2) }}</span>
            </div>

            @foreach ($byProject as $row)
                <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800">{{ $row['project']?->name ?? 'Unknown project' }}</h2>
                            <p class="text-sm text-gray-500">{{ $row['project']?->client?->name }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-gray-800">{{ number_format($row['total_hours'], 2) }} hours</p>
                            <p class="text-sm text-gray-500">${{ number_format($row['total_amount'], 2) }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-gray-200">
                        <div class="p-6">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">By week</h3>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-gray-500 uppercase">
                                        <th class="py-2">Week of</th>
                                        <th class="py-2 text-right">Hours</th>
                                        <th class="py-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($row['by_week'] as $week)
                                        <tr>
                                            <td class="py-2">{{ $week['label'] }}</td>
                                            <td class="py-2 text-right">{{ number_format($week['hours'], 2) }}</td>
                                            <td class="py-2 text-right">${{ number_format($week['amount'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="border-t border-gray-200">
                                    <tr class="font-semibold">
                                        <td class="py-2">Total</td>
                                        <td class="py-2 text-right">{{ number_format($row['total_hours'], 2) }}</td>
                                        <td class="py-2 text-right">${{ number_format($row['total_amount'], 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="p-6">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">By month</h3>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-gray-500 uppercase">
                                        <th class="py-2">Month</th>
                                        <th class="py-2 text-right">Hours</th>
                                        <th class="py-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($row['by_month'] as $month)
                                        <tr>
                                            <td class="py-2">{{ $month['label'] }}</td>
                                            <td class="py-2 text-right">{{ number_format($month['hours'], 2) }}</td>
                                            <td class="py-2 text-right">${{ number_format($month['amount'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="border-t border-gray-200">
                                    <tr class="font-semibold">
                                        <td class="py-2">Total</td>
                                        <td class="py-2 text-right">{{ number_format($row['total_hours'], 2) }}</td>
                                        <td class="py-2 text-right">${{ number_format($row['total_amount'], 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection

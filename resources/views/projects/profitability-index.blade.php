@extends('layouts.app')
@section('title', 'Project Profitability')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Project Profitability</h1>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Billable Revenue</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Staff Cost</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Profit</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Margin</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($projects as $row)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $row->project->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $row->project->client?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ ucfirst(str_replace('_', ' ', $row->project->status)) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-green-600">${{ number_format($row->revenue, 2) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-red-600">${{ number_format($row->cost, 2) }}</td>
                        <td class="px-4 py-3 text-sm text-right font-medium {{ $row->profit < 0 ? 'text-red-600' : 'text-indigo-600' }}">${{ number_format($row->profit, 2) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format($row->margin, 1) }}%</td>
                        <td class="px-4 py-3 text-sm text-right">
                            <a href="{{ route('projects.profitability.show', $row->project) }}"
                                class="text-indigo-600 hover:text-indigo-800">Detail</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">No projects yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection

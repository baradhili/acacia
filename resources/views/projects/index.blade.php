@extends('layouts.app')
@section('title', 'Projects')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Projects</h1>
        <a href="{{ route('projects.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + New Project
        </a>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">PO</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Budget Hours</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Logged Hours</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Utilization</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($projects as $project)
                    <tr>
                        <td class="px-6 py-4">
                            <a href="{{ route('projects.show', $project) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                                {{ $project->name }}
                            </a>
                        </td>
                        <td class="px-6 py-4 text-gray-900">{{ $project->client->name ?? '-' }}</td>
                        <td class="px-6 py-4 text-gray-900">
                            @if($project->purchaseOrder)
                                <a href="{{ route('purchase-orders.show', $project->purchaseOrder) }}" class="text-indigo-600 hover:text-indigo-800">
                                    {{ $project->purchaseOrder->po_number }}
                                </a>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @php
                                $statusColors = [
                                    'active' => 'bg-green-100 text-green-800',
                                    'on_hold' => 'bg-yellow-100 text-yellow-800',
                                    'completed' => 'bg-blue-100 text-blue-800',
                                    'cancelled' => 'bg-red-100 text-red-800',
                                ];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusColors[$project->status] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ ucfirst(str_replace('_', ' ', $project->status)) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ $project->budget_hours ? number_format($project->budget_hours, 1) : '-' }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">{{ $project->total_hours ? number_format($project->total_hours, 1) : '0.0' }}</td>
                        <td class="px-6 py-4 text-right">
                            @if($project->budget_hours)
                                <span class="text-gray-900">{{ number_format($project->budget_utilization, 1) }}%</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('projects.show', $project) }}" class="text-indigo-600 hover:text-indigo-900 mr-3">View</a>
                            <a href="{{ route('projects.edit', $project) }}" class="text-gray-600 hover:text-gray-900 mr-3">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-4 text-center text-gray-500">No projects found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $projects->links() }}
    </div>

@endsection
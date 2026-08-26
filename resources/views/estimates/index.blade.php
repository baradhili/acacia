@extends('layouts.app')
@section('title', 'Estimates')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Estimates / Quotes</h1>
        <a href="{{ route('estimates.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
            + New Estimate
        </a>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estimate #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valid Until</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($estimates as $estimate)
                    <tr>
                        <td class="px-6 py-4">
                            <a href="{{ route('estimates.show', $estimate) }}" class="text-indigo-600 hover:text-indigo-900 font-medium">
                                {{ $estimate->estimate_number }}
                            </a>
                            <x-document-icon :count="$estimate->documents_count" />
                        </td>
                        <td class="px-6 py-4 text-gray-900">{{ $estimate->client->name }}</td>
                        <td class="px-6 py-4 text-right text-gray-900">${{ number_format($estimate->total, 2) }}</td>
                        <td class="px-6 py-4 text-gray-900 {{ $estimate->is_expired ? 'text-red-600' : '' }}">
                            {{ $estimate->valid_until->format('d M Y') }}
                            @if($estimate->is_expired && $estimate->status !== 'converted')
                                <span class="text-xs">(Expired)</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            @php
                                $statusColors = [
                                    'draft' => 'bg-gray-100 text-gray-800',
                                    'sent' => 'bg-blue-100 text-blue-800',
                                    'accepted' => 'bg-green-100 text-green-800',
                                    'rejected' => 'bg-red-100 text-red-800',
                                    'expired' => 'bg-orange-100 text-orange-800',
                                    'converted' => 'bg-purple-100 text-purple-800',
                                ];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $statusColors[$estimate->status] }}">
                                {{ ucfirst($estimate->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('estimates.show', $estimate) }}" class="text-indigo-600 hover:text-indigo-900">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No estimates found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $estimates->links() }}
    </div>

@endsection

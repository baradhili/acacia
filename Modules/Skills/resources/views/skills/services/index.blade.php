@extends('layouts.app')
@section('title', 'Service skills')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Service skills</h1>
        <p class="text-sm text-gray-500 mt-1">
            The service catalogue and the skills each service requires.
        </p>
    </div>

    @include('skills.tabs', ['active' => 'services'])

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Service</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Standard rate</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Required skills</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($services as $service)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $service->name }}</div>
                                @if ($service->description)
                                    <div class="text-xs text-gray-500">{{ Str::limit($service->description, 90) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $service->formattedRate() }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $skillCounts[$service->id] ?? 0 }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('skills.services.show', $service) }}"
                                    class="text-indigo-600 hover:text-indigo-900 font-medium">
                                    {{ $canManage ? 'Manage skills' : 'View skills' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">No services in the catalogue yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

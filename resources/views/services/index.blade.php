@extends('layouts.app')
@section('title', 'Services')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Services</h1>
            <p class="text-sm text-gray-500 mt-1">
                Catalogue of services the practice sells. The standard hourly rate prefills rate cards
                and time billing; fixed-fee services leave it blank.
            </p>
        </div>
        <a href="{{ route('services.create') }}"
            class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">Add service</a>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Service</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Hourly rate</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($services as $service)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('services.show', $service) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">
                                    {{ $service->name }}
                                </a>
                                @if ($service->description)
                                    <span class="block text-xs text-gray-400">{{ Str::limit($service->description, 90) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right">{{ $service->formattedRate() }}</td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('services.edit', $service) }}" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">Edit</a>
                                <form method="POST" action="{{ route('services.destroy', $service) }}" class="inline"
                                    onsubmit="return confirm('Delete this service?');">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:text-red-800 text-sm font-medium">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-sm text-gray-500">
                                No services defined yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

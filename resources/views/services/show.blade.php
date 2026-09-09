@extends('layouts.app')
@section('title', $service->name)
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $service->name }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('services.edit', $service) }}"
                class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">Edit</a>
            <form method="POST" action="{{ route('services.destroy', $service) }}"
                onsubmit="return confirm('Delete this service?');">
                @csrf @method('DELETE')
                <button class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm">Delete</button>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 space-y-4">
        <div class="flex justify-between border-b border-gray-100 pb-4">
            <span class="text-sm font-medium text-gray-500">Standard hourly rate (ex GST)</span>
            <span class="text-sm text-gray-900">{{ $service->formattedRate() }}</span>
        </div>
        <div>
            <p class="text-sm font-medium text-gray-500 mb-1">Description</p>
            <p class="text-sm text-gray-900 whitespace-pre-line">{{ $service->description ?? '—' }}</p>
        </div>
    </div>

    <p class="mt-4">
        <a href="{{ route('services.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800">&larr; Back to services</a>
    </p>
@endsection

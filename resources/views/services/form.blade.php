@extends('layouts.app')
@section('title', $service->exists ? 'Edit Service' : 'Add Service')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $service->exists ? 'Edit ' . $service->name : 'Add Service' }}</h1>
    </div>

    @if ($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <ul class="list-disc list-inside text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $service->exists ? route('services.update', $service) : route('services.store') }}"
          class="bg-white rounded-lg shadow p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf
        @if ($service->exists)
            @method('PUT')
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="name">Service name *</label>
            <input id="name" name="name" type="text" required value="{{ old('name', $service->name) }}" placeholder="BAS Preparation"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="hourly_rate">Standard hourly rate (ex GST)</label>
            <input id="hourly_rate" name="hourly_rate" type="number" step="0.0001" min="0"
                value="{{ old('hourly_rate', $service->hourly_rate) }}" placeholder="150.00"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <p class="mt-1 text-xs text-gray-500">Leave blank for fixed-fee services. Up to 4 decimal places.</p>
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1" for="description">Description</label>
            <textarea id="description" name="description" rows="4"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $service->description) }}</textarea>
        </div>

        <div class="md:col-span-2 flex justify-end gap-2">
            <a href="{{ route('services.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-sm">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">
                {{ $service->exists ? 'Save service' : 'Add service' }}
            </button>
        </div>
    </form>
@endsection

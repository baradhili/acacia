@extends('layouts.app')
@section('title', $domain->exists ? 'Edit Domain' : 'Add Domain')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $domain->exists ? 'Edit ' . $domain->name : 'Add Domain' }}</h1>
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
          action="{{ $domain->exists ? route('domains.update', $domain) : route('domains.store') }}"
          class="bg-white rounded-lg shadow p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf
        @if ($domain->exists)
            @method('PUT')
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="name">Domain name *</label>
            <input id="name" name="name" type="text" required value="{{ old('name', $domain->name) }}" placeholder="example.com.au"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="registrar">Registrar</label>
            <input id="registrar" name="registrar" type="text" value="{{ old('registrar', $domain->registrar) }}"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="purchased_at">Purchased</label>
            <input id="purchased_at" name="purchased_at" type="date" value="{{ old('purchased_at', optional($domain->purchased_at)->format('Y-m-d')) }}"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="expiry_date">Renewal due</label>
            <input id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date', optional($domain->expiry_date)->format('Y-m-d')) }}"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="cost">Capitalised cost (ex GST)</label>
            <input id="cost" name="cost" type="number" step="0.01" min="0" value="{{ old('cost', $domain->cost ?? 0) }}"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="useful_life_months">Useful life (months) — finite only</label>
            <input id="useful_life_months" name="useful_life_months" type="number" min="1"
                value="{{ old('useful_life_months', $domain->useful_life_months) }}"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div class="md:col-span-2">
            <label class="flex items-center">
                <input type="checkbox" name="indefinite_life" value="1" {{ old('indefinite_life', $domain->indefinite_life ? '1' : '') ? 'checked' : '' }}
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                <span class="ml-2 text-sm text-gray-700">
                    Indefinite useful life — no amortisation (the usual treatment for domain names)
                </span>
            </label>
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="2"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('notes', $domain->notes) }}</textarea>
        </div>

        <div class="md:col-span-2 flex justify-end gap-2">
            <a href="{{ route('domains.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-sm">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">
                {{ $domain->exists ? 'Save domain' : 'Add domain' }}
            </button>
        </div>
    </form>
@endsection

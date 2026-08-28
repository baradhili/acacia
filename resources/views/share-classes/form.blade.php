@extends('layouts.app')
@section('title', $shareClass->exists ? 'Edit '.$shareClass->code : 'New Share Class')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $shareClass->exists ? "Edit {$shareClass->code}" : 'New Share Class' }}</h1>
        <a href="{{ route('share-classes.index') }}" class="text-sm text-indigo-600 hover:underline">← Share classes</a>
    </div>

    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow p-6 max-w-2xl">
        <form method="POST"
            action="{{ $shareClass->exists ? route('share-classes.update', $shareClass) : route('share-classes.store') }}">
            @csrf
            @if($shareClass->exists) @method('PUT') @endif

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                    <input type="text" name="code" value="{{ old('code', $shareClass->code) }}" maxlength="10" required
                        {{ $shareClass->exists ? 'disabled' : '' }}
                        class="w-full border-gray-300 rounded-lg disabled:bg-gray-100">
                    @error('code') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="description" value="{{ old('description', $shareClass->description) }}" maxlength="60"
                        class="w-full border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ranking (1 = ordinary)</label>
                    <input type="number" name="ranking" value="{{ old('ranking', $shareClass->ranking ?? 1) }}" min="1" max="99" required
                        class="w-full border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full border-gray-300 rounded-lg">
                        <option value="A" {{ old('status', $shareClass->status ?? 'A') === 'A' ? 'selected' : '' }}>Active</option>
                        <option value="I" {{ old('status', $shareClass->status ?? 'A') === 'I' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="mt-4 space-y-2">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="voting_rights" value="1"
                        {{ old('voting_rights', $shareClass->voting_rights ?? true) ? 'checked' : '' }} class="rounded">
                    Voting rights
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="dividend_rights" value="1"
                        {{ old('dividend_rights', $shareClass->dividend_rights ?? true) ? 'checked' : '' }} class="rounded">
                    Entitled to dividends
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="franking_entitlement" value="1"
                        {{ old('franking_entitlement', $shareClass->franking_entitlement ?? true) ? 'checked' : '' }} class="rounded">
                    Franking credits attach to its dividends
                </label>
            </div>

            <div class="mt-6 flex gap-3">
                <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                    {{ $shareClass->exists ? 'Save changes' : 'Create share class' }}
                </button>
                <a href="{{ route('share-classes.index') }}"
                    class="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</a>
            </div>
        </form>
    </div>
@endsection

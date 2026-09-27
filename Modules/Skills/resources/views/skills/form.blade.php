@extends('layouts.app')
@section('title', $skill->exists ? 'Edit Skill' : 'Add Skill')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ $skill->exists ? 'Edit '.$skill->name : 'Add Skill' }}</h1>
        <a href="{{ route('skills.index') }}"
            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-sm">Back to library</a>
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
          action="{{ $skill->exists ? route('skills.update', $skill) : route('skills.store') }}"
          class="bg-white rounded-lg shadow p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf
        @if ($skill->exists)
            @method('PUT')
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="name">Skill name *</label>
            <input id="name" name="name" type="text" required value="{{ old('name', $skill->name) }}" placeholder="BAS Preparation"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="category">Category</label>
            <input id="category" name="category" type="text" list="skill-categories" value="{{ old('category', $skill->category) }}" placeholder="Tax"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <datalist id="skill-categories">
                @foreach (\Modules\Skills\Models\Skill::whereNotNull('category')->distinct()->orderBy('category')->pluck('category') as $category)
                    <option value="{{ $category }}"></option>
                @endforeach
            </datalist>
            <p class="mt-1 text-xs text-gray-500">Free text — existing categories are offered as you type.</p>
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1" for="description">Description</label>
            <textarea id="description" name="description" rows="4"
                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $skill->description) }}</textarea>
        </div>

        <div class="md:col-span-2 flex justify-end gap-2">
            <a href="{{ route('skills.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 text-sm">Cancel</a>
            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">
                {{ $skill->exists ? 'Save skill' : 'Add skill' }}
            </button>
        </div>
    </form>
@endsection

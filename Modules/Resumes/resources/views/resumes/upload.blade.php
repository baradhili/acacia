@extends('layouts.app')
@section('title', 'Upload resume')
@section('content')

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Upload resume</h1>
        <p class="text-sm text-gray-500 mt-1">
            A JSON file on the <span class="font-medium">JSON Resume v1.0.0 schema</span> (the baradhili
            modification — see the schema's README). Name and email are filled in from the payee
            record when the file leaves them out.
        </p>
    </div>

    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('resumes.store') }}" enctype="multipart/form-data"
        class="bg-white rounded-lg shadow p-6 max-w-2xl space-y-4">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Payee</label>
            <select name="employee_id" required class="w-full border-gray-300 rounded-lg text-sm">
                <option value="" disabled {{ old('employee_id') ? '' : 'selected' }}>Select a payee…</option>
                @foreach ($employees as $id => $name)
                    <option value="{{ $id }}" {{ old('employee_id') == $id ? 'selected' : '' }}>{{ $name }}</option>
                @endforeach
            </select>
            @error('employee_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Resume file (JSON)</label>
            <input type="file" name="resume_file" accept=".json,application/json" required
                class="w-full border-gray-300 rounded-lg text-sm">
            <p class="text-xs text-gray-500 mt-1">
                Up to {{ config('resumes.upload_max_kb') / 1024 }} MB. The file must parse as JSON and
                match the schema — validation errors name the exact field paths.
            </p>
            @error('resume_file') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('resumes.index') }}"
                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Cancel</a>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Upload</button>
        </div>
    </form>
@endsection

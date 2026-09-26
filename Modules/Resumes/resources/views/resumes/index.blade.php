@extends('layouts.app')
@section('title', 'Resumes')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Resumes</h1>
            <p class="text-sm text-gray-500 mt-1">
                @if ($employee)
                    Resumes for <span class="font-medium text-gray-700">{{ $employee->name }}</span> —
                @endif
                Uploaded on the JSON Resume schema, keyword-tailored and exportable to PDF, DOCX or JSON.
            </p>
        </div>
        @if ($canUpload)
            <a href="{{ route('resumes.create') }}"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">Upload resume</a>
        @endif
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Resume</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sections</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uploaded</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($resumes as $resume)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $resume->employee?->name ?? '—' }}</div>
                                @if ($resume->employee)
                                    <div class="text-xs text-gray-500">{{ ucfirst($resume->employee->employment_type) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                <div class="font-medium text-gray-900">{{ $resume->name }}</div>
                                <div class="text-xs text-gray-500">{{ $resume->original_filename }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ collect(['work', 'education', 'skills', 'projects', 'certificates'])
                                    ->filter(fn ($section) => count($resume->parsed_data[$section] ?? []))
                                    ->map(fn ($section) => ucfirst($section).' ('.count($resume->parsed_data[$section]).')')
                                    ->implode(' · ') ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                <div>{{ $resume->uploaded_at?->format('d M Y') }}</div>
                                <div class="text-xs text-gray-500">{{ $resume->uploadedBy?->name ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('resumes.show', $resume) }}"
                                    class="text-indigo-600 hover:text-indigo-900 font-medium">View</a>
                                @if ($resume->canBeManagedBy(auth()->user()))
                                    <form method="POST" action="{{ route('resumes.destroy', $resume) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                No resumes yet{{ $employee ? ' for '.$employee->name : '' }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

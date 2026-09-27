@php
    // This partial renders inside the Payroll payee view (included
    // there behind a Route::has guard on the Resumes routes), so the
    // Resumes module owns both the markup and the query — the
    // Payroll controller never sees resume data.
    $resumes = \Modules\Resumes\Models\Resume::where('employee_id', $employee->id)
        ->with('uploadedBy')
        ->orderByDesc('uploaded_at')
        ->get();
@endphp

<div class="bg-white rounded-lg shadow p-6 max-w-3xl mt-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-semibold text-gray-800">Resumes</h2>
        <a href="{{ route('resumes.index', ['employee' => $employee->id]) }}"
            class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">All resumes for this staff member</a>
    </div>
    @if ($resumes->isNotEmpty())
        <ul class="divide-y divide-gray-200 text-sm">
            @foreach ($resumes as $resume)
                <li class="py-2 flex items-center justify-between gap-4">
                    <a href="{{ route('resumes.show', $resume) }}"
                        class="font-medium text-gray-900 hover:text-indigo-600">{{ $resume->name }}</a>
                    <span class="text-xs text-gray-500 whitespace-nowrap">
                        uploaded {{ $resume->uploaded_at?->format('d M Y') }} by {{ $resume->uploadedBy?->name ?? '—' }}
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-sm text-gray-500">No resumes uploaded for this staff member yet.</p>
    @endif
</div>

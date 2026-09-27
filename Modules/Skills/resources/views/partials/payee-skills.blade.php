@php
    // This partial renders inside the Payroll payee form (included
    // there behind a Route::has guard on the Skills routes), so the
    // Skills module owns both the markup and the query — the Payroll
    // controller never sees skills data.
    $held = \Modules\Skills\Models\EmployeeSkill::where('employee_id', $employee->id)
        ->with('skill')
        ->get()
        ->sortBy(fn ($link) => $link->skill?->name ?? '');
    $labels = \Modules\Skills\Models\Skill::proficiencies();
@endphp

<div class="bg-white rounded-lg shadow p-6 max-w-3xl mt-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-semibold text-gray-800">Skills held</h2>
        <a href="{{ route('skills.employees.show', $employee) }}"
            class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">Manage skills</a>
    </div>
    @if ($held->isNotEmpty())
        <div class="flex flex-wrap gap-2">
            @foreach ($held as $link)
                <span class="inline-flex items-baseline gap-1.5 px-2 py-1 rounded-full bg-gray-100 text-sm">
                    <a href="{{ route('skills.show', $link->skill) }}"
                        class="font-medium text-gray-900 hover:text-indigo-600">{{ $link->skill->name }}</a>
                    <span class="text-xs text-gray-500">{{ $labels[$link->proficiency] ?? ucfirst($link->proficiency) }}</span>
                </span>
            @endforeach
        </div>
    @else
        <p class="text-sm text-gray-500">No skills recorded for this staff member yet.</p>
    @endif
</div>

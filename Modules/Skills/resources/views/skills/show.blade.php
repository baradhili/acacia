@extends('layouts.app')
@section('title', $skill->name.' — skill')
@section('content')

    @php($proficiencyColors = [
        'beginner' => 'bg-gray-100 text-gray-700',
        'intermediate' => 'bg-blue-100 text-blue-700',
        'advanced' => 'bg-indigo-100 text-indigo-700',
        'expert' => 'bg-emerald-100 text-emerald-700',
    ])

    <div class="mb-6 flex justify-between items-start">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $skill->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                @if ($skill->category)<span class="inline-block px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800 font-medium text-xs">{{ $skill->category }}</span> · @endif
                {{ $skill->employees->count() }} {{ Str::plural('staff member', $skill->employees->count()) }} ·
                {{ $skill->services->count() }} {{ Str::plural('service', $skill->services->count()) }}
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('skills.index') }}"
                class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Library</a>
            @if ($canManage)
                <a href="{{ route('skills.edit', $skill) }}"
                    class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Edit</a>
                <form method="POST" action="{{ route('skills.destroy', $skill) }}">
                    @csrf @method('DELETE')
                    <button class="px-3 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700"
                        onclick="return confirm('Delete this skill and its links?')">Delete</button>
                </form>
            @endif
        </div>
    </div>

    @if ($skill->description)
        <div class="mb-6 bg-white rounded-lg shadow p-4 text-sm text-gray-700">{{ $skill->description }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-3 bg-gray-50 text-xs font-medium text-gray-500 uppercase border-b border-gray-200">
                Staff with this skill
            </div>
            <ul class="divide-y divide-gray-200 text-sm">
                @forelse ($skill->employees as $employee)
                    <li class="px-4 py-3 flex items-center justify-between">
                        <a href="{{ route('skills.employees.show', $employee) }}" class="font-medium text-gray-900 hover:text-indigo-600">
                            {{ $employee->name }}
                        </a>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $proficiencyColors[$employee->pivot->proficiency] ?? $proficiencyColors['beginner'] }}">
                            {{ ucfirst($employee->pivot->proficiency) }}
                        </span>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-gray-500">No staff hold this skill yet.</li>
                @endforelse
            </ul>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-3 bg-gray-50 text-xs font-medium text-gray-500 uppercase border-b border-gray-200">
                Services requiring this skill
            </div>
            <ul class="divide-y divide-gray-200 text-sm">
                @forelse ($skill->services as $service)
                    <li class="px-4 py-3 flex items-center justify-between">
                        <a href="{{ route('skills.services.show', $service) }}" class="font-medium text-gray-900 hover:text-indigo-600">
                            {{ $service->name }}
                        </a>
                        <span class="text-gray-500 text-xs">{{ $service->formattedRate() }}{{ $service->hourly_rate !== null ? '/hr' : '' }}</span>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-gray-500">No services require this skill yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
@endsection

@extends('layouts.app')
@section('title', $employee->name.' — skills')
@section('content')

    <div class="mb-6 flex justify-between items-start">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">{{ $employee->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ ucfirst($employee->employment_type) }} ·
                skills held at a proficiency level, from the practice's register.
            </p>
        </div>
        <a href="{{ route('skills.employees.index') }}"
            class="px-3 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">All staff</a>
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

    @if ($canManage)
        <p class="mb-4 text-sm text-gray-500">
            Tick the skills this staff member holds and pick how practised they are at each —
            <span class="font-medium text-gray-700">Beginner, Intermediate, Advanced or Expert</span>
            (the default is Beginner). Unticking removes the link on save.
        </p>

        <div x-data="{ category: '' }" class="bg-white rounded-lg shadow overflow-hidden">
            @include('skills.partials.category-filter', ['skills' => $skills])

            <form method="POST" action="{{ route('skills.employees.update', $employee) }}"
                class="divide-y divide-gray-200">
                @csrf
                @if ($skills->isNotEmpty())
                    <div class="flex items-center gap-4 px-4 py-2 text-xs font-medium text-gray-500 uppercase">
                        <span class="grow pl-7">Skill</span>
                        <span class="w-44 text-right">Proficiency</span>
                    </div>
                @endif
                @foreach ($skills as $skill)
                    <div class="flex items-center gap-4 px-4 py-3 hover:bg-gray-50"
                        x-show="category === '' || category === ($el.dataset.category || '__none')"
                        data-category="{{ filled($skill->category) ? 'c:'.$skill->category : '' }}">
                        <label class="flex items-center gap-3 grow cursor-pointer">
                            <input type="checkbox" name="skills[{{ $skill->id }}]" value="1"
                                {{ isset($current[$skill->id]) ? 'checked' : '' }}
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                <span class="font-medium text-gray-900">{{ $skill->name }}</span>
                                @if (filled($skill->category))
                                    <span class="ml-2 text-xs text-gray-500">{{ $skill->category }}</span>
                                @endif
                            </span>
                        </label>
                        <select name="proficiency[{{ $skill->id }}]"
                            class="w-44 border-gray-300 rounded-md text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($proficiencies as $level => $label)
                                <option value="{{ $level }}"
                                    {{ ($current[$skill->id] ?? 'beginner') === $level ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach

                @if ($skills->isEmpty())
                    <div class="px-4 py-8 text-center text-gray-500 text-sm">
                        The skill library is empty — add skills first.
                    </div>
                @endif

                <div class="px-4 py-3 bg-gray-50 flex justify-end gap-2">
                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm">
                        Save skills
                    </button>
                </div>
            </form>
        </div>
    @else
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-4 py-3 bg-gray-50 text-xs font-medium text-gray-500 uppercase border-b border-gray-200">
                Skills held
            </div>
            <ul class="divide-y divide-gray-200 text-sm">
                @forelse ($skills->filter(fn ($skill) => isset($current[$skill->id])) as $skill)
                    <li class="px-4 py-3 flex items-center justify-between">
                        <a href="{{ route('skills.show', $skill) }}" class="font-medium text-gray-900 hover:text-indigo-600">{{ $skill->name }}</a>
                        <span class="text-gray-600">{{ $proficiencies[$current[$skill->id]] ?? 'Beginner' }}</span>
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-gray-500">No skills recorded for this staff member yet.</li>
                @endforelse
            </ul>
        </div>
    @endif
@endsection

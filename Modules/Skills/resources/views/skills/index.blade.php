@extends('layouts.app')
@section('title', 'Skills')
@section('content')

    <div x-data="{ importing: false }">
        <div class="mb-6 flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Skills</h1>
                <p class="text-sm text-gray-500 mt-1">
                    The practice's skill register — who holds each skill and which services require it.
                </p>
            </div>
            @if ($canManage)
                <div class="flex gap-2">
                    <button type="button" @click="importing = ! importing"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm">Import RSD</button>
                    <a href="{{ route('skills.create') }}"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm">Add skill</a>
                </div>
            @endif
        </div>

        @if ($canManage)
            <form method="POST" action="{{ route('skills.rsd') }}" enctype="multipart/form-data"
                x-show="importing" style="display: none"
                class="mb-4 bg-white rounded-lg shadow p-4">
                @csrf
                <label class="block text-sm font-medium text-gray-700 mb-1" for="rsd-files">
                    Rich Skills Descriptor files
                </label>
                <input id="rsd-files" type="file" name="files[]" multiple required accept=".json,application/json"
                    class="block w-full text-sm text-gray-600 border-gray-300 rounded-lg">
                <p class="mt-2 text-xs text-gray-500">
                    One JSON descriptor per file — skillName, skillStatement and category are imported and the
                    full descriptor kept. Re-importing a descriptor updates the existing skill (matched on its
                    RSD id); bad files are reported and skipped, never blocking the rest.
                </p>
                <div class="mt-3 flex justify-end gap-2">
                    <button type="button" @click="importing = false"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Cancel</button>
                    <button type="submit"
                        class="px-5 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Import</button>
                </div>
            </form>
        @endif
    </div>

    @include('skills.tabs', ['active' => 'library'])

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        {{-- the RSD importer's per-file skips land here: a mixed batch shows both banners --}}
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">{{ session('error') }}</div>
    @endif

    <form method="GET" class="mb-4 bg-white rounded-lg shadow p-4 flex flex-wrap items-end gap-3">
        <div class="grow">
            <label class="block text-xs font-medium text-gray-500 uppercase mb-1" for="q">Search</label>
            <input id="q" type="text" name="q" value="{{ request('q') }}" placeholder="e.g. payroll, bas, xero"
                class="w-full border-gray-300 rounded-lg text-sm">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 uppercase mb-1" for="category">Category</label>
            <select id="category" name="category" class="border-gray-300 rounded-lg text-sm">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" {{ request('category') === $category ? 'selected' : '' }}>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filter</button>
        @if (request('q') || request('category'))
            <a href="{{ route('skills.index') }}"
                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 text-sm hover:bg-gray-50">Clear</a>
        @endif
    </form>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Skill</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Staff</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Services</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($skills as $skill)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('skills.show', $skill) }}" class="font-medium text-gray-900 hover:text-indigo-600">{{ $skill->name }}</a>
                                @if ($skill->description)
                                    <div class="text-xs text-gray-500">{{ Str::limit($skill->description, 90) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $skill->category ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $skill->employees_count }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $skill->services_count }}</td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <a href="{{ route('skills.show', $skill) }}"
                                    class="text-indigo-600 hover:text-indigo-900 font-medium">View</a>
                                @if ($canManage)
                                    <a href="{{ route('skills.edit', $skill) }}"
                                        class="text-gray-500 hover:text-gray-700 font-medium">Edit</a>
                                    <form method="POST" action="{{ route('skills.destroy', $skill) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-800 font-medium"
                                            onclick="return confirm('Delete this skill and its links?')">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">No skills found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $skills->links() }}</div>
@endsection

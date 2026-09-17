@extends('layouts.app')
@section('title', 'Modules')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Modules</h1>
            <p class="text-sm text-gray-500 mt-1">
                Installed feature modules — enable or disable them, update git-installed ones, and
                install new modules straight from a GitHub repository.
            </p>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800">Installed</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Module</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Version</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($modules as $module)
                        <tr>
                            <td class="px-4 py-3">
                                <p class="text-sm font-medium text-gray-900">{{ $module['name'] }}</p>
                                <p class="text-xs text-gray-500">{{ $module['description'] }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $module['version'] }}</td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                @if ($module['git'])
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-800">git</span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600">shipped</span>
                                @endif
                                @if ($module['core'])
                                    <span class="ml-1 px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800"
                                        title="Ships with the app — cannot be uninstalled">core</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                    {{ $module['enabled'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $module['enabled'] ? 'enabled' : 'disabled' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if ($module['enabled'])
                                    <form action="{{ route('modules.disable', $module['name']) }}" method="POST" class="inline">
                                        @csrf
                                        <button class="text-gray-600 hover:text-gray-900 text-sm font-medium">Disable</button>
                                    </form>
                                @else
                                    <form action="{{ route('modules.enable', $module['name']) }}" method="POST" class="inline">
                                        @csrf
                                        <button class="text-green-600 hover:text-green-800 text-sm font-medium">Enable</button>
                                    </form>
                                @endif
                                @if ($module['git'])
                                    <form action="{{ route('modules.update', $module['name']) }}" method="POST" class="inline ml-3">
                                        @csrf
                                        <button class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">Update</button>
                                    </form>
                                @endif
                                @unless ($module['core'])
                                    <form action="{{ route('modules.uninstall', $module['name']) }}" method="POST" class="inline ml-3"
                                        onsubmit="return confirm('Uninstall {{ $module['name'] }}? Its tables will be dropped and the module directory deleted.');">
                                        @csrf
                                        <input type="hidden" name="drop_data" value="1">
                                        <button class="text-red-600 hover:text-red-800 text-sm font-medium">Uninstall</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-sm text-gray-500">No modules installed.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Install from GitHub</h2>
        <p class="text-sm text-gray-500 mb-4">
            The repository is cloned into <code>Modules/</code>, its manifest validated, its migrations run and
            the module enabled — a failed install leaves nothing behind. Only https GitHub URLs.
        </p>
        <form action="{{ route('modules.install') }}" method="POST" class="flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label for="repository" class="block text-sm font-medium text-gray-700 mb-1">Repository URL</label>
                <input type="text" name="repository" id="repository" required maxlength="500"
                    value="{{ old('repository') }}" placeholder="https://github.com/you/erp-some-module"
                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('repository') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 shrink-0">
                Install
            </button>
        </form>
    </div>
@endsection

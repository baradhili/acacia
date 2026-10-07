@extends('layouts.app')
@section('title', __('backups.heading'))
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">{{ __('backups.heading') }}</h1>
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
    @if ($errors->any())
        <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Status cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 max-w-5xl">
        <div class="bg-white rounded-lg shadow p-5">
            <h3 class="text-sm font-medium text-gray-500">{{ __('backups.last_backup') }}</h3>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $setting->last_backup_at?->format('d M Y H:i') ?? __('backups.never') }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ __('backups.schedule') }}: {{ $setting->frequency }}</p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <h3 class="text-sm font-medium text-gray-500">{{ __('backups.destinations') }}</h3>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ implode(', ', $destinationDisks) }}</p>
            <p class="mt-1 text-xs {{ count($destinationDisks) > 1 ? 'text-gray-500' : 'text-yellow-600' }}">
                {{ count($destinationDisks) > 1 ? __('backups.destinations_help') : __('backups.offsite_pending') }}
            </p>
        </div>
        <div class="bg-white rounded-lg shadow p-5">
            <h3 class="text-sm font-medium text-gray-500">{{ __('backups.encryption') }}</h3>
            <p class="mt-1 text-lg font-semibold {{ $encrypted ? 'text-green-600' : 'text-yellow-600' }}">
                {{ __($encrypted ? 'backups.encryption_on' : 'backups.encryption_off') }}
            </p>
            <p class="mt-1 text-sm text-gray-500">{{ __('backups.retention') }}: <code>{{ $retention }}</code></p>
        </div>
    </div>

    {{-- Actions --}}
    <div class="bg-white rounded-lg shadow p-6 max-w-5xl mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('backups.run_now') }}</h2>
                <p class="text-sm text-gray-500 mb-3">{{ __('backups.run_now_help') }}</p>
                <form method="POST" action="{{ route('backups.run') }}">
                    @csrf
                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                        {{ __('backups.run_now') }}
                    </button>
                </form>
            </div>
            <div>
                <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('backups.verify_now') }}</h2>
                <p class="text-sm text-gray-500 mb-3">{{ __('backups.verify_now_help') }}</p>
                <form method="POST" action="{{ route('backups.verify') }}">
                    @csrf
                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                        {{ __('backups.verify_now') }}
                    </button>
                </form>
            </div>
            <div>
                <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('backups.test_restore') }}</h2>
                <p class="text-sm text-gray-500 mb-3">{{ __('backups.test_restore_help') }}</p>
                <form method="POST" action="{{ route('backups.test-restore') }}">
                    @csrf
                    <button type="submit"
                        class="px-4 py-2 bg-purple-600 text-white text-sm font-medium rounded-md hover:bg-purple-700">
                        {{ __('backups.test_restore') }}
                    </button>
                </form>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-t border-gray-100 mt-6 pt-4">
            <div>
                <h3 class="text-sm font-medium text-gray-700 mb-1">{{ __('backups.last_restore_test') }}</h3>
                @if ($restoreTests->isNotEmpty())
                    @php $latestTest = $restoreTests->first(); @endphp
                    <p class="text-sm {{ $latestTest->status === 'passed' ? 'text-green-600' : 'text-red-600' }}">
                        {{ $latestTest->created_at->format('d M Y H:i') }} — {{ strtoupper($latestTest->status) }} ({{ $latestTest->duration_ms }} ms)
                    </p>
                @else
                    <p class="text-sm text-yellow-600">{{ __('backups.no_restore_tests') }}</p>
                @endif
            </div>
            <div>
                <h3 class="text-sm font-medium text-gray-700 mb-1">&nbsp;</h3>
                <p class="text-xs text-gray-500 font-mono">{{ __('backups.restore_is_cli') }}</p>
            </div>
        </div>
    </div>

    {{-- Archives --}}
    <div class="bg-white rounded-lg shadow p-6 max-w-5xl mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('backups.archives') }}</h2>
        @if ($archives->isEmpty())
            <p class="text-sm text-gray-500">{{ __('backups.no_archives') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                            <th class="py-2 pr-4">{{ __('backups.col_disk') }}</th>
                            <th class="py-2 pr-4">{{ __('backups.col_file') }}</th>
                            <th class="py-2 pr-4">{{ __('backups.col_size') }}</th>
                            <th class="py-2 pr-4">{{ __('backups.col_created') }}</th>
                            <th class="py-2 pr-4">{{ __('backups.col_status') }}</th>
                            <th class="py-2">{{ __('backups.col_verified') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($archives as $archive)
                            <tr>
                                <td class="py-2 pr-4 text-gray-700">{{ $archive->disk }}</td>
                                <td class="py-2 pr-4 font-mono text-xs text-gray-600">{{ $archive->name }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ \Illuminate\Support\Number::fileSize($archive->bytes) }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $archive->backed_up_at?->format('d M Y H:i') ?? '-' }}</td>
                                <td class="py-2 pr-4">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        {{ $archive->status === 'ok' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                        {{ $archive->status }}
                                    </span>
                                </td>
                                <td class="py-2 text-gray-600">{{ $archive->verified_at?->format('d M Y H:i') ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Integrity snapshots + restore tests --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-5xl mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('backups.integrity_snapshots') }}</h2>
            @if ($snapshots->isEmpty())
                <p class="text-sm text-gray-500">{{ __('backups.no_snapshots') }}</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($snapshots as $snapshot)
                        <li class="py-2">
                            <p class="text-xs text-gray-400">{{ __('backups.col_snapshot') }} #{{ $snapshot->id }} — {{ $snapshot->created_at->format('d M Y H:i') }}</p>
                            <p class="text-sm text-gray-700">{{ $snapshot->summary }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('backups.restore_tests') }}</h2>
            @if ($restoreTests->isEmpty())
                <p class="text-sm text-gray-500">{{ __('backups.no_restore_tests_yet') }}</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($restoreTests as $test)
                        <li class="py-2">
                            <p class="text-xs text-gray-400">{{ $test->created_at->format('d M Y H:i') }} — {{ $test->file }}</p>
                            <p class="text-sm {{ $test->status === 'passed' ? 'text-green-600' : 'text-red-600' }}">
                                {{ strtoupper($test->status) }} ({{ $test->duration_ms }} ms): {{ $test->message }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- Schedule settings --}}
    <div class="bg-white rounded-lg shadow p-6 max-w-5xl">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('backups.schedule') }}</h2>
        <p class="text-sm text-gray-500 mb-4">{{ __('backups.schedule_help') }}</p>

        <form method="POST" action="{{ route('backups.settings.update') }}" class="flex items-end gap-3">
            @csrf
            @method('PUT')

            <div>
                <label for="frequency" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.frequency') }}</label>
                <select name="frequency" id="frequency"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach (\App\Models\BackupSetting::FREQUENCIES as $frequency)
                        <option value="{{ $frequency }}"
                            {{ old('frequency', $setting->frequency) === $frequency ? 'selected' : '' }}>
                            {{ ucfirst($frequency) }}
                        </option>
                    @endforeach
                </select>
                @error('frequency') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 shrink-0">
                {{ __('backups.save') }}
            </button>
        </form>
    </div>
@endsection

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

    {{-- A destination root outside PHP's open_basedir paths: nothing
         on this page can reach that disk, so say so and how to fix it
         instead of failing mid-action. --}}
    @if ($unreachableDisks !== [])
        <div class="mb-6 max-w-5xl bg-orange-50 border border-orange-300 text-orange-900 px-4 py-3 rounded-lg">
            <p class="font-semibold">{{ __('backups.basedir_warning_title') }}</p>
            @foreach ($unreachableDisks as $blocked)
                <p class="mt-2 text-sm">
                    {{ __('backups.basedir_warning_body', ['disk' => $blocked['disk'], 'root' => $blocked['root'], 'allowed' => $blocked['allowed']]) }}
                </p>
            @endforeach
            <ul class="mt-2 list-disc list-inside text-sm">
                <li>{{ __('backups.basedir_option_env') }}</li>
                <li>{{ __('backups.basedir_option_ini') }}</li>
            </ul>
        </div>
    @endif

    {{-- A destination that threw on access (dead credentials,
         detached volume): reported, not fatal, and its archives are
         not marked missing. --}}
    @if ($inaccessibleDisks !== [])
        <div class="mb-6 max-w-5xl bg-orange-50 border border-orange-300 text-orange-900 px-4 py-3 rounded-lg">
            <p class="font-semibold">{{ __('backups.inaccessible_warning_title') }}</p>
            @foreach ($inaccessibleDisks as $broken)
                <p class="mt-2 text-sm">
                    {{ __('backups.inaccessible_warning_body', ['disk' => $broken['disk'], 'error' => $broken['error']]) }}
                </p>
            @endforeach
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

    {{-- Offsite destination (Tao head 3 — separation): every backup
         run also lands on this remote disk. Credentials are encrypted
         at rest; the save always connection-tests, and enabling is
         gated on the test passing. --}}
    <div class="bg-white rounded-lg shadow p-6 max-w-5xl mb-6" x-data="{ driver: '{{ old('driver', $offsiteDisk->driver) }}' }">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">{{ __('backups.offsite_heading') }}</h2>
        <p class="text-sm text-gray-500 mb-4">{{ __('backups.offsite_help') }}</p>

        @if ($offsiteUnavailable)
            <div class="mb-4 bg-orange-50 border border-orange-300 text-orange-900 px-4 py-3 rounded-lg text-sm">
                {{ __('backups.offsite_unavailable_warning') }}
            </div>
        @endif

        <form method="POST" action="{{ route('backups.offsite.update') }}" class="space-y-4">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="driver" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_driver') }}</label>
                    <select name="driver" id="driver" x-model="driver"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($offsiteDrivers as $name => $meta)
                            <option value="{{ $name }}"
                                @unless($meta['available']) disabled @endunless
                                {{ $name === old('driver', $offsiteDisk->driver) ? 'selected' : '' }}>
                                {{ $meta['label'] }}@unless($meta['available']) — {{ __('backups.offsite_driver_unavailable', ['install' => $meta['install']]) }}@endunless
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="root" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_root') }}</label>
                    <input type="text" name="root" id="root" value="{{ old('root', $offsiteDisk->root) }}"
                        placeholder="/srv/backups/acacia"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                    <p class="mt-1 text-xs text-gray-500">{{ __('backups.offsite_root_help') }}</p>
                </div>
                <div class="flex items-end pb-1">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="enabled" value="1"
                            @checked(old('enabled', $offsiteDisk->enabled))
                            class="rounded border-gray-300 text-indigo-600 shadow-xs focus:ring-indigo-500">
                        {{ __('backups.offsite_enabled') }}
                    </label>
                </div>
            </div>

            {{-- S3 credentials --}}
            <div x-show="driver === 's3'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="key" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_key') }}</label>
                    <input type="text" name="key" id="key" value="{{ old('key', $offsiteDisk->config['key'] ?? '') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="secret" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_secret') }}</label>
                    <input type="password" name="secret" id="secret" value="" autocomplete="new-password"
                        placeholder="{{ ($offsiteDisk->config['secret'] ?? null) ? __('backups.offsite_secret_keep') : '' }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="region" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_region') }}</label>
                    <input type="text" name="region" id="region" value="{{ old('region', $offsiteDisk->config['region'] ?? '') }}"
                        placeholder="ap-southeast-2"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="bucket" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_bucket') }}</label>
                    <input type="text" name="bucket" id="bucket" value="{{ old('bucket', $offsiteDisk->config['bucket'] ?? '') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="endpoint" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_endpoint') }}</label>
                    <input type="text" name="endpoint" id="endpoint" value="{{ old('endpoint', $offsiteDisk->config['endpoint'] ?? '') }}"
                        placeholder="https://s3.example.internal"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="flex items-end pb-1">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="use_path_style_endpoint" value="1"
                            @checked(old('use_path_style_endpoint', $offsiteDisk->config['use_path_style_endpoint'] ?? false))
                            class="rounded border-gray-300 text-indigo-600 shadow-xs focus:ring-indigo-500">
                        {{ __('backups.offsite_f_path_style') }}
                    </label>
                </div>
            </div>

            {{-- SFTP credentials --}}
            <div x-show="driver === 'sftp'" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="host" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_host') }}</label>
                    <input type="text" name="host" id="host" value="{{ old('host', $offsiteDisk->config['host'] ?? '') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="port" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_port') }}</label>
                    <input type="number" name="port" id="port" value="{{ old('port', $offsiteDisk->config['port'] ?? 22) }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_username') }}</label>
                    <input type="text" name="username" id="username" value="{{ old('username', $offsiteDisk->config['username'] ?? '') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_password') }}</label>
                    <input type="password" name="password" id="password" value="" autocomplete="new-password"
                        placeholder="{{ ($offsiteDisk->config['password'] ?? null) ? __('backups.offsite_secret_keep') : '' }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div class="md:col-span-2">
                    <label for="private_key" class="block text-sm font-medium text-gray-700 mb-1">{{ __('backups.offsite_f_private_key') }}</label>
                    <textarea name="private_key" id="private_key" rows="3"
                        placeholder="{{ ($offsiteDisk->config['private_key'] ?? null) ? __('backups.offsite_secret_keep') : __('backups.offsite_f_private_key_help') }}"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 font-mono text-xs"></textarea>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                    {{ __('backups.offsite_save') }}
                </button>
                @if ($offsiteDisk->last_test_at)
                    <p class="text-sm {{ $offsiteDisk->lastTestPassed() ? 'text-green-600' : 'text-red-600' }}">
                        {{ __('backups.offsite_last_test', [
                            'status' => __($offsiteDisk->lastTestPassed() ? 'backups.offsite_last_test_passed' : 'backups.offsite_last_test_failed'),
                            'when' => $offsiteDisk->last_test_at->format('d M Y H:i'),
                        ]) }}
                        @if ($offsiteDisk->last_test_message)— {{ $offsiteDisk->last_test_message }}@endif
                    </p>
                @endif
            </div>
        </form>
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

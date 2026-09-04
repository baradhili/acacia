@extends('layouts.app')
@section('title', 'Backups')
@section('content')

    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Backups</h1>
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

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl mb-6">
        <div class="flex justify-between items-start gap-4 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-800 mb-1">Backup now</h2>
                <p class="text-sm text-gray-500">
                    Creates a gzipped database dump in <code>db/</code> and a tar.gz of the stored
                    files (uploads, logos, profile photos) in <code>files/</code> under
                    <code>{{ $destination }}</code>, then prunes old archives per the schedule
                    below. Restore procedures are in the backup &amp; restore runbook.
                </p>
            </div>
            <form method="POST" action="{{ route('backups.run') }}" class="shrink-0">
                @csrf
                <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 shrink-0">
                    Run Backup Now
                </button>
            </form>
        </div>

        <dl class="grid grid-cols-2 gap-4 text-sm border-y border-gray-100 py-4 mb-4">
            <div>
                <dt class="font-medium text-gray-700">Last successful backup</dt>
                <dd class="text-gray-500">{{ $setting->last_backup_at?->format('d M Y H:i') ?? 'never' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700">Schedule</dt>
                <dd class="text-gray-500">
                    {{ $setting->frequency }} — the scheduler runs daily and the backup itself
                    decides when it is due
                </dd>
            </div>
        </dl>

        <h3 class="text-sm font-semibold text-gray-700 mb-2">Existing backups</h3>
        @if (count($archives['db']) + count($archives['files']) === 0)
            <p class="text-sm text-gray-500">No backups yet — run one above or wait for the schedule.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                        <th class="py-2 pr-4">Type</th>
                        <th class="py-2 pr-4">File</th>
                        <th class="py-2 pr-4">Size</th>
                        <th class="py-2">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach (['db' => 'Database', 'files' => 'Files'] as $type => $label)
                        @foreach ($archives[$type] as $file)
                            <tr>
                                <td class="py-2 pr-4 text-gray-700">{{ $label }}</td>
                                <td class="py-2 pr-4 font-mono text-xs text-gray-600">{{ $file['name'] }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $file['size'] }}</td>
                                <td class="py-2 text-gray-600">{{ $file['at'] }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Schedule &amp; retention</h2>
        <p class="text-sm text-gray-500 mb-4">
            How often the scheduled backup runs, and how many archives of each type (database
            dump, files archive) are kept — the oldest beyond that are deleted after each run.
            Requires the server's <code>schedule:run</code> cron entry.
        </p>

        <form method="POST" action="{{ route('backups.settings.update') }}" class="flex items-end gap-3">
            @csrf
            @method('PUT')

            <div>
                <label for="frequency" class="block text-sm font-medium text-gray-700 mb-1">Frequency</label>
                <select name="frequency" id="frequency"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach (\App\Models\BackupSetting::FREQUENCIES as $frequency)
                        <option value="{{ $frequency }}"
                            {{ old('frequency', $setting->frequency) === $frequency ? 'selected' : '' }}>
                            {{ ucfirst($frequency) }}
                        </option>
                    @endforeach
                </select>
                @error('frequency') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="retention_count" class="block text-sm font-medium text-gray-700 mb-1">Backups kept (of each type)</label>
                <input type="number" name="retention_count" id="retention_count" min="1" max="365"
                    value="{{ old('retention_count', $setting->retention_count) }}"
                    class="mt-1 block w-40 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('retention_count') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 shrink-0">
                Save
            </button>
        </form>
    </div>
@endsection

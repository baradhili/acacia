<?php

namespace App\Http\Controllers;

use App\Models\BackupArchive;
use App\Models\BackupIntegritySnapshot;
use App\Models\BackupOffsiteDisk;
use App\Models\BackupRestoreTest;
use App\Models\BackupSetting;
use App\Services\Backups\ArchiveInventory;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\DiskAccess;
use App\Services\Backups\OffsiteDisk;
use App\Services\Backups\RestoreTester;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The admin Backups page: the Tao-of-Backup control surface. Run a
 * backup now (BackupRunner → spatie), verify archive checksums and
 * source integrity, fire a test-restore (never touches live data),
 * manage the frequency, and configure the offsite destination (s3 or
 * sftp, credentials encrypted at rest — OffsiteDisk publishes it as
 * the `offsite` disk). The heavy lifting lives in the services so
 * the console commands and this page share one path; live restores
 * are console-only (`backups:restore`). Admin only (route
 * middleware).
 */
class BackupController extends Controller
{
    public function __construct(
        protected BackupRunner $runner,
        protected ArchiveInventory $inventory,
        protected RestoreTester $restoreTester,
        protected DiskAccess $diskAccess,
        protected OffsiteDisk $offsiteDisk,
    ) {}

    public function index()
    {
        $reconciled = $this->inventory->reconcile();
        $offsite = BackupOffsiteDisk::current();

        return view('backups.index', [
            'setting' => BackupSetting::current(),
            'archives' => BackupArchive::query()->orderByDesc('backed_up_at')->limit(20)->get(),
            'snapshots' => BackupIntegritySnapshot::query()->latest('id')->limit(5)->get(),
            'restoreTests' => BackupRestoreTest::query()->latest('id')->limit(5)->get(),
            'destinationDisks' => config('backup.backup.destination.disks'),
            'unreachableDisks' => $this->diskAccess->unreachableDestinationDisks(),
            'inaccessibleDisks' => $reconciled['inaccessible'],
            'offsiteDisk' => $offsite,
            'offsiteDrivers' => $this->offsiteDisk->drivers(),
            'offsiteUnavailable' => $offsite->enabled && ! $this->offsiteDisk->driverAvailable($offsite->driver),
            'retention' => $this->retentionSummary(),
            'encrypted' => config('backup.backup.password') !== null,
        ]);
    }

    public function run()
    {
        set_time_limit(0);

        $result = $this->runner->run(force: true);

        return match ($result['status']) {
            'ran' => redirect()->route('backups.index')->with(
                'success',
                __('backups.run_created', ['count' => count($result['created'])]),
            ),
            'already_running' => redirect()->route('backups.index')->with('error', __('backups.run_already_running')),
            'skipped' => redirect()->route('backups.index')->with('error', __('backups.run_skipped')),
            'unreachable_disks' => redirect()->route('backups.index')->with('error', __('backups.run_failed_unreachable')),
            default => $this->reportFailure($result['error'] ?? 'unknown error'),
        };
    }

    public function verify()
    {
        set_time_limit(0);

        $counts = $this->inventory->verify();

        if ($counts['corrupt'] > 0 || $counts['missing'] > 0) {
            return redirect()->route('backups.index')->with(
                'error',
                __('backups.verify_problems', $counts),
            );
        }

        if ($counts['inaccessible'] > 0) {
            return redirect()->route('backups.index')->with(
                'error',
                __('backups.verify_inaccessible', ['count' => $counts['inaccessible']]),
            );
        }

        if ($counts['unreachable'] > 0) {
            return redirect()->route('backups.index')->with(
                'error',
                __('backups.verify_unreachable', ['count' => $counts['unreachable']]),
            );
        }

        return redirect()->route('backups.index')->with(
            'success',
            __('backups.verify_clean', ['count' => $counts['ok']]),
        );
    }

    /**
     * Save the offsite destination and immediately connection-test
     * it. Blank credential inputs keep the stored values (masked
     * secrets never round-trip through the browser), and `enabled`
     * only sticks when the probe passed — an unreachable offsite
     * disk would fail every scheduled backup run, so the gate lives
     * here, not in the admin's discipline. A failing probe against
     * an already-enabled destination changes nothing at all: the
     * working config's secrets are unrecoverable through the masked
     * form, so a typo must not destroy them.
     */
    public function updateOffsite(Request $request)
    {
        $validated = $request->validate([
            'driver' => ['required', Rule::in(BackupOffsiteDisk::DRIVERS)],
            'enabled' => ['nullable', 'boolean'],
            'root' => ['nullable', 'string', 'max:255'],
            // sftp
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'private_key' => ['nullable', 'string', 'max:16384'],
            // s3
            'key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            'use_path_style_endpoint' => ['nullable', 'boolean'],
        ]);

        if (! $this->offsiteDisk->driverAvailable($validated['driver'])) {
            return redirect()->route('backups.index')->with(
                'error',
                __('backups.offsite_driver_missing', [
                    'driver' => $validated['driver'],
                    'install' => $this->offsiteDisk->drivers()[$validated['driver']]['install'],
                ]),
            );
        }

        $row = BackupOffsiteDisk::current();
        $existing = $row->config ?? [];

        // Only the chosen driver's fields carry over; switching
        // drivers drops the other bundle's stale entries.
        $fields = $validated['driver'] === 's3'
            ? ['key', 'secret', 'region', 'bucket', 'endpoint']
            : ['host', 'port', 'username', 'password', 'private_key'];

        $config = [];
        foreach ($fields as $field) {
            $submitted = $validated[$field] ?? null;
            $value = ($submitted !== null && $submitted !== '') ? $submitted : ($existing[$field] ?? null);

            if ($value !== null && $value !== '') {
                $config[$field] = $value;
            }
        }

        // The integer rule validates but does not cast, and forms
        // submit strings — SftpConnectionProvider declares int $port.
        if (isset($config['port'])) {
            $config['port'] = (int) $config['port'];
        }

        if ($validated['driver'] === 's3') {
            $config['use_path_style_endpoint'] = (bool) ($validated['use_path_style_endpoint'] ?? false);
        }

        $required = $validated['driver'] === 's3' ? ['key', 'secret', 'bucket'] : ['host', 'username'];
        $missing = array_values(array_filter($required, fn (string $field) => empty($config[$field])));

        if ($missing !== []) {
            return redirect()->route('backups.index')->withErrors([
                'offsite' => __('backups.offsite_missing_fields', ['fields' => implode(', ', $missing)]),
            ]);
        }

        // Probe the candidate before touching the stored row. An
        // enabled destination is a known-working config whose secrets
        // the masked inputs cannot give back — a failing replacement
        // (typo, not-yet-live rotated password) must leave it exactly
        // as it is, not overwrite it and silently drop the offsite
        // leg from every future backup.
        $candidate = $row->replicate();
        $candidate->driver = $validated['driver'];
        $candidate->root = ($validated['root'] ?? null) !== null && $validated['root'] !== '' ? $validated['root'] : null;
        $candidate->config = $config;

        $probe = $this->offsiteDisk->probe($candidate);

        if (! $probe['ok'] && $row->enabled) {
            return redirect()->route('backups.index')->with(
                'error',
                __('backups.offsite_edit_refused', ['error' => $probe['message']]),
            );
        }

        $row->driver = $candidate->driver;
        $row->root = $candidate->root;
        $row->config = $config;

        $row->last_test_at = now();
        $row->last_test_status = $probe['ok'] ? 'passed' : 'failed';
        $row->last_test_message = $probe['message'];
        $row->enabled = (bool) ($validated['enabled'] ?? false) && $probe['ok'];
        $row->save();

        $this->offsiteDisk->sync();

        if (! $probe['ok']) {
            return redirect()->route('backups.index')->with(
                'error',
                __('backups.offsite_enable_refused', ['error' => $probe['message']]),
            );
        }

        return redirect()->route('backups.index')->with(
            'success',
            $row->enabled ? __('backups.offsite_saved_enabled') : __('backups.offsite_saved_disabled'),
        );
    }

    public function testRestore()
    {
        set_time_limit(0);

        $test = $this->restoreTester->test();

        return redirect()->route('backups.index')->with(
            $test->status === 'passed' ? 'success' : 'error',
            __('backups.test_restore_'.$test->status, ['file' => $test->file, 'message' => $test->message]),
        );
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'frequency' => ['required', Rule::in(BackupSetting::FREQUENCIES)],
        ]);

        BackupSetting::current()->fill($validated)->save();

        return redirect()->route('backups.index')
            ->with('success', __('backups.settings_saved', ['frequency' => $validated['frequency']]));
    }

    /**
     * The GFS retention policy as configured, for the page footer.
     */
    protected function retentionSummary(): string
    {
        $policy = config('backup.cleanup.default_strategy');

        return sprintf(
            '%s d / %s w / %s m / %s y',
            $policy['keep_all_backups_for_days'],
            $policy['keep_weekly_backups_for_weeks'],
            $policy['keep_monthly_backups_for_months'],
            $policy['keep_yearly_backups_for_years'],
        );
    }

    protected function reportFailure(string $error)
    {
        // Details go to the log; the screen gets the generic wording —
        // the raw error may carry paths or internals.
        Log::error('Backup run failed from the admin page', ['error' => $error]);

        return redirect()->route('backups.index')->with('error', __('backups.run_failed'));
    }
}

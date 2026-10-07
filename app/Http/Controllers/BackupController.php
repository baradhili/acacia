<?php

namespace App\Http\Controllers;

use App\Models\BackupArchive;
use App\Models\BackupIntegritySnapshot;
use App\Models\BackupRestoreTest;
use App\Models\BackupSetting;
use App\Services\Backups\ArchiveInventory;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\RestoreTester;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The admin Backups page: the Tao-of-Backup control surface. Run a
 * backup now (BackupRunner → spatie), verify archive checksums and
 * source integrity, fire a test-restore (never touches live data),
 * and manage the frequency. The heavy lifting lives in the services
 * so the console commands and this page share one path; live
 * restores are console-only (`backups:restore`). Admin only (route
 * middleware).
 */
class BackupController extends Controller
{
    public function __construct(
        protected BackupRunner $runner,
        protected ArchiveInventory $inventory,
        protected RestoreTester $restoreTester,
    ) {}

    public function index()
    {
        $this->inventory->reconcile();

        return view('backups.index', [
            'setting' => BackupSetting::current(),
            'archives' => BackupArchive::query()->orderByDesc('backed_up_at')->limit(20)->get(),
            'snapshots' => BackupIntegritySnapshot::query()->latest('id')->limit(5)->get(),
            'restoreTests' => BackupRestoreTest::query()->latest('id')->limit(5)->get(),
            'destinationDisks' => config('backup.backup.destination.disks'),
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

        return redirect()->route('backups.index')->with(
            'success',
            __('backups.verify_clean', ['count' => $counts['ok']]),
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

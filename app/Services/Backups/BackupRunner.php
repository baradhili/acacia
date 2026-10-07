<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use App\Models\BackupSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The backup orchestrator behind `backups:run` and the admin page's
 * Run Now button. One complete unit per run (Tao head 1): spatie's
 * `backup:run` writes a single zip — database dump, the whole public
 * storage disk, and .env — to every destination disk, then spatie's
 * `backup:clean` applies the GFS retention policy. The wrapper adds
 * what spatie does not: the admin's frequency due-check, the
 * cross-process lock, the archive inventory with checksums, and the
 * post-run integrity snapshot of the source.
 */
class BackupRunner
{
    protected const RUN_LOCK = 'backups:run';

    /** Matches the per-process timeout on dump/archive commands. */
    protected const RUN_LOCK_TTL = 3600;

    public function __construct(
        protected ArchiveInventory $inventory,
        protected IntegrityService $integrity,
    ) {}

    /**
     * Whether a scheduled run should take a backup, per the configured
     * frequency and the last successful run. Manual runs bypass this.
     */
    public function isDue(BackupSetting $setting): bool
    {
        return $setting->last_backup_at === null
            || $setting->last_backup_at->lte(match ($setting->frequency) {
                'weekly' => now()->subWeek(),
                'monthly' => now()->subMonth(),
                default => now()->subDay(),
            });
    }

    /**
     * Full run under an exclusive cross-process lock. Returns one of:
     * `ran` (archives created — with their inventory rows and a fresh
     * integrity snapshot), `skipped` (not due), `already_running`, or
     * `failed` (error message attached; nothing partial is trusted).
     *
     * @return array{status: string, created: list<BackupArchive>, error: ?string, output: string}
     */
    public function run(bool $force = false): array
    {
        $lock = Cache::lock(static::RUN_LOCK, static::RUN_LOCK_TTL);

        if (! $lock->get()) {
            return ['status' => 'already_running', 'created' => [], 'error' => null, 'output' => ''];
        }

        try {
            $setting = BackupSetting::current();

            if (! $force && ! $this->isDue($setting)) {
                return ['status' => 'skipped', 'created' => [], 'error' => null, 'output' => ''];
            }

            $exit = Artisan::call('backup:run', ['--no-interaction' => true]);
            $output = Artisan::output();

            if ($exit !== 0) {
                Log::error('Backup failed', ['output' => $output]);

                return ['status' => 'failed', 'created' => [], 'error' => trim($output) ?: 'backup:run exited with code '.$exit, 'output' => $output];
            }

            // Retention is a separate concern: a cleanup failure must
            // not mark a good backup as failed, but it must be heard.
            $cleanupExit = Artisan::call('backup:clean', ['--no-interaction' => true]);
            if ($cleanupExit !== 0) {
                Log::warning('Backup cleanup failed after a successful backup', ['output' => Artisan::output()]);
            }

            $reconciled = $this->inventory->reconcile();
            $setting->recordSuccess();

            // The snapshot is tied to the newest archive so a restore
            // test can compare its imported counts against the state
            // this backup captured.
            $this->integrity->snapshot($reconciled['new'][0] ?? null);

            return ['status' => 'ran', 'created' => $reconciled['new'], 'error' => null, 'output' => $output];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'failed', 'created' => [], 'error' => $e->getMessage(), 'output' => ''];
        } finally {
            $lock->release();
        }
    }
}

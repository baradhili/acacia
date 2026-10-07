<?php

namespace App\Console\Commands\Backups;

use App\Models\BackupArchive;
use App\Services\Backups\BackupRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

/**
 * Back up the whole application unit per the admin's schedule:
 * BackupRunner drives spatie's backup:run + backup:clean (one zip per
 * destination disk — database dump, public storage disk, .env), then
 * records the archive inventory and a source integrity snapshot. The
 * scheduler invokes this daily; the internal due-check makes
 * weekly/monthly frequencies behave, and --force bypasses it (manual
 * and pre-restore runs).
 */
class BackupsRun extends Command
{
    protected $signature = 'backups:run
                            {--force : Run even if the schedule says a backup is not due}';

    protected $description = 'Create a complete backup (database + stored files + .env), apply retention, verify inventory';

    public function handle(BackupRunner $runner): int
    {
        $result = $runner->run((bool) $this->option('force'));

        return match ($result['status']) {
            'skipped' => $this->info('Backup not due — use --force to run anyway.') ?: Command::SUCCESS,
            'already_running' => $this->warn('Another backup is already running; this run did nothing.') ?: Command::SUCCESS,
            'failed' => $this->reportFailure($result),
            default => $this->reportSuccess($result),
        };
    }

    /**
     * @param  array{error: ?string, output: string}  $result
     */
    protected function reportFailure(array $result): int
    {
        $this->error('Backup failed: '.$result['error']);
        Log::error('Backup failed', ['error' => $result['error']]);

        return Command::FAILURE;
    }

    /**
     * @param  array{created: list<BackupArchive>, output: string}  $result
     */
    protected function reportSuccess(array $result): int
    {
        foreach ($result['created'] as $archive) {
            $this->line(sprintf(
                '  %-12s %s (%s)',
                $archive->disk,
                $archive->name,
                Number::fileSize($archive->bytes),
            ));
        }

        if ($result['created'] === []) {
            $this->warn('Backup ran but no new archive was recorded — check the destination disks.');
        }

        $this->info('Backup completed; inventory and integrity snapshot updated.');
        Log::info('Backup completed', ['archives' => count($result['created'])]);

        return Command::SUCCESS;
    }
}

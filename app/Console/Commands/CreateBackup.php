<?php

namespace App\Console\Commands;

use App\Models\BackupSetting;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

/**
 * Back up the database and stored files per the admin's schedule, then
 * prune old archives (docs/runbooks/backup-restore.md). The scheduler
 * invokes this daily; the internal due-check makes weekly/monthly
 * frequencies behave, and a missed day simply backs up at the next
 * tick. --force bypasses the schedule (manual/admin runs).
 */
class CreateBackup extends Command
{
    protected $signature = 'backup:create
                            {--force : Run even if the schedule says a backup is not due}
                            {--keep= : Override the configured retention count for this run}';

    protected $description = 'Back up the database and stored files, then prune old backups';

    public function handle(BackupService $backups): int
    {
        $setting = BackupSetting::current();

        if (! $this->option('force') && ! $backups->isDue($setting)) {
            $this->info(sprintf(
                'Backup not due (frequency: %s, last run %s). Use --force to run anyway.',
                $setting->frequency,
                $setting->last_backup_at->format('d M Y H:i'),
            ));

            return Command::SUCCESS;
        }

        $this->info('Creating backup (database + stored files)...');

        try {
            ['created' => $created, 'removed' => $removed] = $backups->runAndPrune(
                $this->option('keep') !== null ? (int) $this->option('keep') : null,
            );
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            Log::error('Backup failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }

        foreach ($created as $type => $file) {
            $this->line(sprintf('  %-5s %s (%s)', $type, $file['name'], Number::fileSize($file['bytes'])));
        }

        if ($removed !== []) {
            $keep = (int) ($this->option('keep') ?: BackupSetting::current()->retention_count);
            $this->info('Pruned '.count($removed)." old backup(s), keeping the most recent {$keep}.");
        }

        Log::info('Backup completed', ['files' => $created, 'pruned' => count($removed)]);
        $this->info('Backup completed.');

        return Command::SUCCESS;
    }
}

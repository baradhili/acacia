<?php

namespace App\Console\Commands\Backups;

use App\Models\BackupArchive;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\Restorer;
use Illuminate\Console\Command;

/**
 * The live restore (Tao head 8) — the destructive one. Always takes a
 * fresh pre-restore backup first, then rebuilds the database from the
 * chosen archive (any retained zip = point-in-time choice), with the
 * Restorer's safety copy and rollback beneath it. Guarded: needs an
 * explicit --file and --force, and reminds the operator that web and
 * queue processes must be restarted around a sqlite swap. Selective
 * file restores unpack straight from the zip — see the runbook.
 */
class BackupsRestore extends Command
{
    protected $signature = 'backups:restore
                            {--disk= : Destination disk holding the archive}
                            {--file= : The archive zip to restore from}
                            {--force : Actually restore (without it, the command only explains)}';

    protected $description = 'Restore the database from a backup archive, taking a safety backup first';

    public function handle(BackupRunner $runner, Restorer $restorer): int
    {
        if (! $this->option('file')) {
            $this->explain();

            return Command::INVALID;
        }

        $archive = BackupArchive::query()
            ->where('name', $this->option('file'))
            ->when($this->option('disk'), fn ($query) => $query->where('disk', $this->option('disk')))
            ->latest('id')
            ->first();

        if ($archive === null) {
            $this->error('No inventoried archive matches that disk/file combination.');

            return Command::FAILURE;
        }

        if ($archive->status !== BackupArchive::STATUS_OK) {
            $this->error("Refusing to restore from a {$archive->status} archive (run backups:verify to re-check it).");

            return Command::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn("Would restore the database from {$archive->disk}/{$archive->name}.");
            $this->line('A pre-restore backup runs first; the Restorer keeps a safety copy and rolls back on failure.');
            $this->line('Restart web and queue processes after a sqlite restore. Re-run with --force to proceed.');

            return Command::SUCCESS;
        }

        if (! $this->confirm("Restore the database from {$archive->name}? The current state is backed up first")) {
            return Command::SUCCESS;
        }

        $this->info('Taking the pre-restore backup...');
        $pre = $runner->run(force: true);

        if ($pre['status'] !== 'ran') {
            $this->error('The pre-restore backup did not succeed ('.$pre['status'].') — aborting without touching live data.');

            return Command::FAILURE;
        }

        try {
            $result = $restorer->restore($archive);

            $this->info(sprintf(
                'Database restored from %s/%s (%d tables, %s driver). Safety copy: %s',
                $archive->disk,
                $archive->name,
                $result['tables'],
                $result['driver'],
                $result['safety_copy'],
            ));
            $this->warn('Restart web and queue processes now, then run `php artisan backups:test-restore`.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());
            $this->line('The pre-restore backup taken above is intact; the Restorer rolled back where it could.');

            return Command::FAILURE;
        }
    }

    protected function explain(): void
    {
        $this->info('Restores the live database from a backup archive.');

        foreach (BackupArchive::query()->orderByDesc('backed_up_at')->limit(10)->get() as $archive) {
            $this->line(sprintf('  %-12s %s  [%s]', $archive->disk, $archive->name, $archive->status));
        }

        $this->line('Usage: php artisan backups:restore --file=<zip> [--disk=backups] --force');
        $this->line('For a dry run without touching anything: php artisan backups:test-restore');
    }
}

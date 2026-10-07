<?php

namespace App\Console\Commands\Backups;

use App\Models\BackupArchive;
use App\Services\Backups\RestoreTester;
use Illuminate\Console\Command;

/**
 * Prove a backup restores (Tao head 5): pull the database dump out of
 * an archive, rebuild it into a scratch sqlite database (live data is
 * never touched), run the integrity check and compare row counts
 * against the snapshot taken with that backup. "Would you be
 * comfortable erasing your disk right now?" — this command is how
 * you get to answer yes without finding out the hard way.
 */
class BackupsTestRestore extends Command
{
    protected $signature = 'backups:test-restore
                            {--disk= : Destination disk of the archive to test}
                            {--file= : Archive file name to test (default: newest ok archive)}';

    protected $description = 'Restore the newest (or given) backup into a scratch database and verify it';

    public function handle(RestoreTester $tester): int
    {
        $archive = null;

        if ($this->option('file')) {
            $archive = BackupArchive::query()
                ->where('name', $this->option('file'))
                ->when($this->option('disk'), fn ($query) => $query->where('disk', $this->option('disk')))
                ->latest('id')
                ->first();

            if ($archive === null) {
                $this->error('No inventoried archive matches that disk/file combination.');

                return Command::FAILURE;
            }
        }

        $test = $tester->test($archive);

        $this->line(sprintf(
            '%s on %s/%s (%d ms): %s',
            strtoupper($test->status),
            $test->disk,
            $test->file,
            $test->duration_ms,
            $test->message,
        ));

        return $test->status === 'passed' ? Command::SUCCESS : Command::FAILURE;
    }
}

<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use App\Models\BackupIntegritySnapshot;
use App\Models\BackupRestoreTest;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The restore test (Tao head 5): an untested backup is not a backup.
 * This restores the database dump from a chosen archive into a
 * scratch sqlite database — live data is never touched — runs
 * PRAGMA integrity_check, and compares the imported row counts
 * against the integrity snapshot taken with that archive. Every run
 * records a BackupRestoreTest row for the admin page and history.
 */
class RestoreTester
{
    /**
     * Test the given archive, or the newest ok one when null.
     */
    public function test(?BackupArchive $archive = null): BackupRestoreTest
    {
        $started = microtime(true);

        $archive ??= BackupArchive::query()
            ->where('status', BackupArchive::STATUS_OK)
            ->orderByDesc('backed_up_at')
            ->first();

        if ($archive === null) {
            return $this->record(null, null, 'failed', [], 'No backup archive is available to test.', $started);
        }

        $checks = [];

        try {
            $sql = BackupZip::withDatabaseDump($archive, fn (string $dump) => $dump);
            $checks['dump_bytes'] = strlen($sql);

            [$pdo, $scratchPath] = $this->freshScratch();

            try {
                SqlDumpImport::import($pdo, $sql);

                $checks['integrity_check'] = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();

                $restoredCounts = $this->scratchTableCounts($pdo);
                $checks['tables_restored'] = count($restoredCounts);

                $reference = BackupIntegritySnapshot::referenceFor($archive);
                $differences = [];

                if ($reference !== null) {
                    foreach ($reference->manifest['db']['tables'] ?? [] as $table => $expected) {
                        $actual = $restoredCounts[$table] ?? null;

                        if ($actual !== $expected) {
                            $differences[$table] = ['expected' => $expected, 'restored' => $actual];
                        }
                    }
                }

                $checks['row_counts_match'] = $differences === [];
                $checks['row_count_differences'] = $differences;

                $passed = $checks['integrity_check'] === 'ok' && $differences === [];
                $message = $passed
                    ? 'Restore succeeded; integrity check clean and row counts match the snapshot.'
                    : ($checks['integrity_check'] !== 'ok'
                        ? 'The restored database failed its integrity check.'
                        : 'Restored, but row counts differ from the snapshot taken with this backup.');
            } finally {
                $pdo = null;
                File::delete($scratchPath);
            }

            return $this->record($archive, $archive->disk, $passed ? 'passed' : 'failed', $checks, $message, $started, $archive->name);
        } catch (Throwable $e) {
            return $this->record($archive, $archive->disk, 'failed', $checks, $e->getMessage(), $started, $archive->name);
        }
    }

    /**
     * @return array{\PDO, string} the connection and its scratch file path
     */
    protected function freshScratch(): array
    {
        $dir = storage_path('app/backup-restore');
        File::ensureDirectoryExists($dir);

        $path = $dir.'/test-'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(3)).'.sqlite';

        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return [$pdo, $path];
    }

    /**
     * @return array<string, int>
     */
    protected function scratchTableCounts(\PDO $pdo): array
    {
        $counts = [];

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $counts[(string) $table] = (int) $pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
        }

        ksort($counts);

        return $counts;
    }

    protected function record(
        ?BackupArchive $archive,
        ?string $disk,
        string $status,
        array $checks,
        string $message,
        float $started,
        ?string $file = null,
    ): BackupRestoreTest {
        $test = new BackupRestoreTest;
        $test->fill([
            'disk' => $disk ?? '-',
            'file' => $file ?? '-',
            'status' => $status,
            'checks' => $checks,
            'message' => $message,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        // Ownership FK — assigned explicitly, never mass-assigned.
        $test->backup_archive_id = $archive?->id;
        $test->save();

        return $test;
    }
}

<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use App\Models\BackupIntegritySnapshot;
use App\Models\BackupRestoreTest;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The restore test (Tao head 5): an untested backup is not a backup.
 * This restores the database dump from a chosen archive into a
 * scratch database — live data is never touched — checks the
 * engine's integrity verdict, and compares the imported row counts
 * against the integrity snapshot taken with that archive. The
 * scratch matches the dump's engine: a sqlite file for the sqlite
 * driver, or a create-and-drop MySQL database on the configured
 * server for the mysql driver (a mysqldump file cannot be imported
 * into sqlite — the dialects differ from the first `unsigned`).
 * Every run records a BackupRestoreTest row for the admin page and
 * history. Archives on open_basedir-blocked disks fail with an
 * explanation rather than the adapter's ErrorException.
 */
class RestoreTester
{
    /** Scratch engines the tester can stand up, keyed by source driver. */
    protected const SCRATCH_DRIVERS = ['sqlite', 'mysql'];

    public function __construct(protected DiskAccess $diskAccess) {}

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

        if (! $this->diskAccess->diskIsReachable($archive->disk)) {
            return $this->record($archive, $archive->disk, 'failed', [], __('backups.test_restore_disk_unreachable', ['disk' => $archive->disk]), $started, $archive->name);
        }

        $driver = $this->sourceDriver();

        if (! in_array($driver, self::SCRATCH_DRIVERS, true)) {
            return $this->record($archive, $archive->disk, 'failed', [], "Restore testing does not support the {$driver} driver's dump dialect yet.", $started, $archive->name);
        }

        $checks = [];

        try {
            $sql = BackupZip::withDatabaseDump($archive, fn (string $dump) => $dump);
            $checks['dump_bytes'] = strlen($sql);
            $checks['scratch_driver'] = $driver;

            $scratch = $this->freshScratch($driver);

            try {
                SqlDumpImport::import($scratch['pdo'], $sql);

                $checks['integrity_check'] = $driver === 'mysql'
                    ? $this->mysqlTablesHealthy($scratch['pdo'])
                    : (string) $scratch['pdo']->query('PRAGMA integrity_check')->fetchColumn();

                $restoredCounts = $this->scratchTableCounts($scratch['pdo'], $driver);
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
                // Release the scratch connection before its database
                // is dropped — dispose's server-side DROP is cleaner
                // with no sessions attached (it closes the server
                // handle itself).
                $scratch['pdo'] = null;
                $scratch['dispose']();
            }

            return $this->record($archive, $archive->disk, $passed ? 'passed' : 'failed', $checks, $message, $started, $archive->name);
        } catch (Throwable $e) {
            return $this->record($archive, $archive->disk, 'failed', $checks, $e->getMessage(), $started, $archive->name);
        }
    }

    /**
     * The connection spatie dumps — the first of
     * backup.backup.source.databases, falling back to the default
     * connection. (An archive predating a connection switch would be
     * relabelled; recording the source per archive is future work.)
     */
    protected function sourceConnection(): string
    {
        $databases = (array) config('backup.backup.source.databases');

        return (string) ($databases[0] ?? config('database.default'));
    }

    /**
     * The engine the archive's dump speaks, resolved from the source
     * connection's own driver — the config list holds connection
     * names, which need not spell their driver. Laravel's mariadb
     * driver speaks the MySQL protocol, so it shares the mysql
     * scratch.
     */
    protected function sourceDriver(): string
    {
        $source = $this->sourceConnection();
        $driver = (string) (config("database.connections.{$source}.driver") ?? $source);

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }

    /**
     * A throwaway database for the scratch restore, disposed by the
     * caller's finally: a sqlite file, or a MySQL database created
     * and dropped on the source connection's server (needs CREATE/
     * DROP privilege for that connection's user — the failure
     * message says so).
     *
     * @return array{pdo: PDO, dispose: callable(): void}
     */
    protected function freshScratch(string $driver): array
    {
        if ($driver !== 'mysql') {
            $dir = storage_path('app/backup-restore');
            File::ensureDirectoryExists($dir);

            $path = $dir.'/test-'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(3)).'.sqlite';

            $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            return ['pdo' => $pdo, 'dispose' => fn () => File::delete($path)];
        }

        $config = (array) config('database.connections.'.$this->sourceConnection());
        $name = 'erp_restore_test_'.now()->format('YmdHis').'_'.bin2hex(random_bytes(3));

        $server = new PDO($this->mysqlDsn($config, null), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            $server = null;

            throw new RuntimeException('Could not create the scratch database — the app DB user may lack CREATE privilege: '.$e->getMessage(), 0, $e);
        }

        try {
            $pdo = new PDO($this->mysqlDsn($config, $name), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            // The database was created but the caller has no dispose
            // yet — drop it here or it orphans on the server.
            $server->exec("DROP DATABASE IF EXISTS `{$name}`");
            $server = null;

            throw $e;
        }

        $dispose = function () use ($server, $name): void {
            try {
                $server->exec("DROP DATABASE IF EXISTS `{$name}`");
            } finally {
                $server = null;
            }
        };

        return ['pdo' => $pdo, 'dispose' => $dispose];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function mysqlDsn(array $config, ?string $database): string
    {
        $dsn = isset($config['unix_socket']) && $config['unix_socket'] !== ''
            ? 'unix_socket='.$config['unix_socket']
            : 'host='.($config['host'] ?? '127.0.0.1').(isset($config['port']) ? ';port='.$config['port'] : '');

        return 'mysql:'.$dsn.($database !== null ? ';dbname='.$database : '').';charset=utf8mb4';
    }

    /**
     * MySQL has no PRAGMA integrity_check — CHECK TABLE over every
     * restored table is the engine's own verdict. 'ok' when every
     * table reports OK, otherwise the offending messages.
     */
    protected function mysqlTablesHealthy(PDO $pdo): string
    {
        $problems = [];

        foreach ($this->scratchTableNames($pdo, 'mysql') as $table) {
            $verdict = $pdo->query("CHECK TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);

            if (is_array($verdict) && strcasecmp((string) ($verdict['Msg_text'] ?? ''), 'ok') !== 0) {
                $problems[] = "{$table}: ".($verdict['Msg_text'] ?? 'unknown verdict');
            }
        }

        return $problems === [] ? 'ok' : implode('; ', $problems);
    }

    /**
     * @return list<string>
     */
    protected function scratchTableNames(PDO $pdo, string $driver): array
    {
        $rows = $pdo->query(
            $driver === 'mysql'
                ? 'SHOW TABLES'
                : "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    /**
     * @return array<string, int>
     */
    protected function scratchTableCounts(PDO $pdo, string $driver): array
    {
        $counts = [];
        $quote = $driver === 'mysql' ? '`' : '"';

        foreach ($this->scratchTableNames($pdo, $driver) as $table) {
            $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$quote}{$table}{$quote}")->fetchColumn();
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

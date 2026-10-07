<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * The live restore behind `backups:restore` (Tao head 8). Safety
 * first, always: the command takes a fresh pre-restore backup before
 * calling this, and the restore itself rebuilds the database into a
 * new sqlite file, validates it (integrity check + a sane table
 * count), and only then swaps it in — any failure past the swap
 * rolls back to the safety copy. Long-running web/queue processes
 * keep the old sqlite inode open after the swap, so the operator
 * must restart workers around a restore (the command and runbook
 * say so). MySQL restores import through the mysql CLI with the same
 * safety-copy-then-verify shape.
 */
class Restorer
{
    /**
     * @return array{safety_copy: string, tables: int, driver: string}
     */
    public function restore(BackupArchive $archive, ?string $targetPath = null): array
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => $this->restoreSqlite($archive, $targetPath),
            'mysql' => $this->restoreMysql($archive),
            $driver => throw new RuntimeException("Restores are not supported for the {$driver} driver."),
        };
    }

    /**
     * SQLite: build a fresh database from the dump, verify, then swap
     * the file in place. $targetPath is injectable so tests restore
     * into a scratch file rather than the live database.
     *
     * @return array{safety_copy: string, tables: int, driver: string}
     */
    protected function restoreSqlite(BackupArchive $archive, ?string $targetPath = null): array
    {
        $targetPath ??= (string) config('database.connections.sqlite.database');

        if ($targetPath === ':memory:' || ! is_file($targetPath)) {
            throw new RuntimeException("Cannot restore onto a non-file database ({$targetPath}).");
        }

        // §8: an automatic pre-restore copy of the current state — in
        // addition to the pre-restore backup zip the command takes.
        $safetyCopy = $targetPath.'.pre-restore-'.now()->format('Ymd_His');
        if (! @copy($targetPath, $safetyCopy)) {
            throw new RuntimeException('Could not create the pre-restore safety copy.');
        }

        $swapped = false;
        $fresh = null;

        try {
            $sql = BackupZip::withDatabaseDump($archive, fn (string $dump) => $dump);

            $fresh = $targetPath.'.restore-'.bin2hex(random_bytes(4));
            $pdo = new \PDO('sqlite:'.$fresh, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

            $this->importSql($pdo, $sql);

            $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                throw new RuntimeException("The rebuilt database failed its integrity check ({$integrity}).");
            }

            $tables = count($this->tableCounts($pdo));
            if ($tables < 5) {
                throw new RuntimeException("The rebuilt database has only {$tables} tables — refusing to swap in a hollow restore.");
            }

            $pdo = null;

            rename($fresh, $targetPath);
            $swapped = true;

            // Post-swap validation: re-open the live path and confirm
            // the swap landed a readable database with the same shape.
            $check = new \PDO('sqlite:'.$targetPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $after = count($this->tableCounts($check));

            if ($after !== $tables) {
                throw new RuntimeException("Post-restore validation failed ({$after} tables readable, expected {$tables}).");
            }

            return ['safety_copy' => $safetyCopy, 'tables' => $tables, 'driver' => 'sqlite'];
        } catch (Throwable $e) {
            if ($swapped) {
                @copy($safetyCopy, $targetPath);
            } elseif ($fresh !== null) {
                // Failed before the swap — drop the half-built rebuild.
                File::delete($fresh);
            }

            throw $e;
        }
    }

    /**
     * MySQL: safety-dump the live database, import the archive's dump
     * through the mysql CLI, and on failure replay the safety dump.
     *
     * @return array{safety_copy: string, tables: int, driver: string}
     */
    protected function restoreMysql(BackupArchive $archive): array
    {
        $config = config('database.connections.mysql');
        $database = (string) $config['database'];

        // Paths and the dump first — the random file suffixes here are
        // name uniquifiers, nowhere near the credential use below.
        $safetyCopy = storage_path('app/backup-restore/pre-restore-'.now()->format('Ymd_His').'.sql.gz');
        $sqlFile = dirname($safetyCopy).'/import-'.bin2hex(random_bytes(4)).'.sql';
        File::ensureDirectoryExists(dirname($safetyCopy));

        $sql = BackupZip::withDatabaseDump($archive, fn (string $dump) => $dump);
        file_put_contents($sqlFile, $sql);

        $connection = ! empty($config['unix_socket'])
            ? '--socket='.escapeshellarg((string) $config['unix_socket'])
            : '--host='.escapeshellarg((string) ($config['host'] ?? '127.0.0.1'))
                .' --port='.(int) ($config['port'] ?? 3306);

        $client = 'mysql '.$connection
            .' --user='.escapeshellarg((string) ($config['username'] ?? ''))
            .' '.escapeshellarg($database);

        $dump = 'mysqldump '.$connection
            .' --user='.escapeshellarg((string) ($config['username'] ?? ''))
            .' --single-transaction --quick --routines '.escapeshellarg($database);

        try {
            $safety = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
                ->timeout(3600)->run($dump.' | gzip -f > '.escapeshellarg($safetyCopy));

            if (! $safety->successful() || ! is_file($safetyCopy) || filesize($safetyCopy) === 0) {
                throw new RuntimeException('Could not create the pre-restore safety dump.');
            }

            $import = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
                ->timeout(3600)->run($client.' < '.escapeshellarg($sqlFile));

            if (! $import->successful()) {
                // Roll back by replaying the safety dump.
                Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
                    ->timeout(3600)->run('gunzip -c '.escapeshellarg($safetyCopy).' | '.$client);

                throw new RuntimeException('The database import failed and was rolled back: '.trim($import->errorOutput()));
            }

            $count = DB::select('SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()');

            return ['safety_copy' => $safetyCopy, 'tables' => (int) reset($count)->c, 'driver' => 'mysql'];
        } finally {
            File::delete($sqlFile);
        }
    }

    /**
     * Execute a textual sqlite dump one statement per line.
     */
    protected function importSql(\PDO $pdo, string $sql): void
    {
        $buffer = '';

        foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
            $buffer .= $line."\n";

            if (str_ends_with(rtrim($line), ';')) {
                $statement = rtrim($buffer);
                $buffer = '';

                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
        }
    }

    /**
     * @return array<string, int>
     */
    protected function tableCounts(\PDO $pdo): array
    {
        $counts = [];

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $counts[(string) $table] = (int) $pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
        }

        return $counts;
    }
}

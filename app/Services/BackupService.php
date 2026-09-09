<?php

namespace App\Services;

use App\Models\BackupSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Creates and prunes the instance-wide backups run by `backup:create`:
 * a gzipped database dump in {backups.path}/db and a tar.gz of the
 * public storage disk (uploads, client/company logos, profile photos)
 * in {backups.path}/files. Retention is count-based per type and set by
 * the admin (BackupSetting). Restore procedures live in
 * docs/runbooks/backup-restore.md.
 */
class BackupService
{
    /**
     * Cross-process guard for the whole backup-and-prune run: the
     * scheduled command and the admin "run now" button must not create
     * archives or prune concurrently (retention counts archives as they
     * land, so overlapping runs would prune each other's output). The
     * TTL only bounds a crashed run — a healthy run releases the lock
     * itself.
     */
    protected const RUN_LOCK = 'backups:run-and-prune';

    /** Matches the per-process timeout on dump/archive commands. */
    protected const RUN_LOCK_TTL = 3600;

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
     * Full run under an exclusive cross-process lock: create both
     * archives (and their temporary files), stamp the last-success time
     * and prune per the configured (or given) retention. Shared by the
     * scheduled command and the admin "run now" button. When another
     * process holds the lock, reports `already_running` instead of
     * running anything.
     *
     * @return array{created: array<string, array{name: string, bytes: int}>, removed: list<string>, already_running: bool}
     */
    public function runAndPrune(?int $keep = null): array
    {
        $lock = Cache::lock(static::RUN_LOCK, static::RUN_LOCK_TTL);

        if (! $lock->get()) {
            return ['created' => [], 'removed' => [], 'already_running' => true];
        }

        try {
            $setting = BackupSetting::current();
            $created = $this->run();
            $setting->recordSuccess();

            return [
                'created' => $created,
                'removed' => $this->prune($keep ?? $setting->retention_count),
                'already_running' => false,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Create both archives. Throws on failure; partial files are
     * removed so a failed run never leaves a truncated archive for
     * retention to count.
     *
     * @return array<string, array{name: string, bytes: int}>
     */
    public function run(): array
    {
        $base = rtrim((string) config('backups.path'), '/');

        // Seconds alone are not unique: sequential runs (or a manual run
        // racing the scheduler) would otherwise land on the same archive
        // paths, so milliseconds and a random suffix widen the stamp
        // while keeping the Ymd_His prefix sortable.
        $stamp = now()->format('Ymd_His_v').'_'.bin2hex(random_bytes(3));

        return [
            'db' => $this->dumpDatabase($base.'/db', $stamp),
            'files' => $this->archiveFiles($base.'/files', $stamp),
        ];
    }

    /**
     * Delete the oldest archives beyond the retention count (applied
     * per type: database dumps and file archives each keep their own
     * N) and return the removed file names.
     *
     * @return list<string>
     */
    public function prune(int $keep): array
    {
        $keep = max(1, $keep);
        $removed = [];

        foreach ($this->archivesByType() as $archives) {
            // Newest first, so everything past the keep window is stale.
            foreach (array_slice($archives, $keep) as $stale) {
                unlink($stale['path']);
                $removed[] = $stale['name'];
            }
        }

        return $removed;
    }

    /**
     * Existing archives for the admin page, newest first.
     *
     * @return array<string, list<array{name: string, bytes: int, at: Carbon}>>
     */
    public function list(): array
    {
        return array_map(
            fn (array $archives) => array_map(
                fn (array $file) => ['name' => $file['name'], 'bytes' => $file['bytes'], 'at' => $file['at']],
                $archives,
            ),
            $this->archivesByType(),
        );
    }

    /**
     * @return array<string, list<array{name: string, bytes: int, at: Carbon, path: string}>> keyed db/files, newest first
     */
    protected function archivesByType(): array
    {
        $patterns = ['db' => '/\.(sql|sqlite)\.gz$/', 'files' => '/\.tar\.gz$/'];
        $base = rtrim((string) config('backups.path'), '/');
        $out = ['db' => [], 'files' => []];

        foreach ($patterns as $type => $pattern) {
            $dir = $base.'/'.$type;
            if (! is_dir($dir)) {
                continue;
            }

            $archives = array_filter(
                glob($dir.'/*') ?: [],
                fn (string $file) => is_file($file) && preg_match($pattern, basename($file)),
            );

            // Newest first; filenames carry Ymd_His, so the name breaks
            // mtime ties (same-second runs).
            usort($archives, fn (string $a, string $b) => [filemtime($b), basename($b)] <=> [filemtime($a), basename($a)]);

            $out[$type] = array_map(
                fn (string $file) => [
                    'name' => basename($file),
                    'bytes' => (int) (filesize($file) ?: 0),
                    'at' => Carbon::createFromTimestamp(filemtime($file) ?: time()),
                    'path' => $file,
                ],
                $archives,
            );
        }

        return $out;
    }

    /**
     * @return array{name: string, bytes: int}
     */
    protected function dumpDatabase(string $dir, string $stamp): array
    {
        $this->ensureDirectory($dir);

        return match (DB::connection()->getDriverName()) {
            'mysql' => $this->dumpMysql($dir, $stamp),
            'sqlite' => $this->dumpSqlite($dir, $stamp),
            $driver => throw new \RuntimeException("Database backups are not supported for the {$driver} driver."),
        };
    }

    /**
     * @return array{name: string, bytes: int}
     */
    protected function dumpMysql(string $dir, string $stamp): array
    {
        $config = config('database.connections.mysql');
        $database = (string) $config['database'];

        // config always defines unix_socket (empty unless DB_SOCKET is
        // set), so an empty one must fall through to host/port.
        $connection = ! empty($config['unix_socket'])
            ? '--socket='.escapeshellarg((string) $config['unix_socket'])
            : '--host='.escapeshellarg((string) ($config['host'] ?? '127.0.0.1'))
                .' --port='.(int) ($config['port'] ?? 3306);

        // Two steps (dump, then gzip) rather than a pipe: each gets a
        // reliable exit code — a broken pipe would otherwise hide a
        // failed dump behind gzip's success. The password travels in
        // MYSQL_PWD rather than on the command line.
        $dump = $dir."/{$database}_{$stamp}.sql";

        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
            ->timeout(3600)
            ->run(
                'mysqldump '.$connection
                .' --user='.escapeshellarg((string) ($config['username'] ?? ''))
                .' --single-transaction --quick --routines --default-character-set=utf8mb4'
                .' '.escapeshellarg($database)
                .' > '.escapeshellarg($dump),
            );

        try {
            if (! $result->successful() || ! is_file($dump) || filesize($dump) === 0) {
                throw new \RuntimeException('mysqldump failed: '.trim($result->errorOutput() ?: $result->output()));
            }

            return $this->gzip($dump);
        } finally {
            @unlink($dump);
        }
    }

    /**
     * @return array{name: string, bytes: int}
     */
    protected function dumpSqlite(string $dir, string $stamp): array
    {
        $database = (string) config('database.connections.sqlite.database');
        $prefix = ($database !== '' && $database !== ':memory:')
            ? basename($database, '.sqlite')
            : 'database';
        $snapshot = $dir."/{$prefix}_{$stamp}.sqlite";

        if ($database === ':memory:' || is_file($database)) {
            // A binary snapshot via VACUUM INTO (consistent even while
            // the database is in use) is preferred. When it cannot run
            // (VACUUM is refused inside a transaction, e.g. in tests),
            // fall back to the textual dump rather than a raw file
            // copy: the dump reads through the live connection, so it
            // includes committed data still sitting in the WAL, which
            // copy() would miss.
            try {
                DB::statement($this->vacuumInto($snapshot));
            } catch (\Throwable) {
                return $this->gzip($this->dumpSqliteStatements($dir, $prefix, $stamp));
            }
        } else {
            throw new \RuntimeException("SQLite database file not found: {$database}");
        }

        try {
            if (! is_file($snapshot) || filesize($snapshot) === 0) {
                throw new \RuntimeException('The SQLite snapshot is empty.');
            }

            return $this->gzip($snapshot);
        } finally {
            @unlink($snapshot);
        }
    }

    /**
     * Write a textual SQLite dump (CREATE statements plus INSERTs,
     * restorable with `sqlite3 database.sqlite < dump.sql`) and return
     * its path.
     */
    protected function dumpSqliteStatements(string $dir, string $prefix, string $stamp): string
    {
        $pdo = DB::connection()->getPdo();
        $tables = DB::select(
            'SELECT name, sql FROM sqlite_master'
            ." WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL",
        );

        $dump = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n";

        foreach ($tables as $table) {
            $dump .= $table->sql.";\n";

            foreach (DB::table($table->name)->get() as $row) {
                $columns = array_keys((array) $row);
                $values = array_map(
                    fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value),
                    (array) $row,
                );

                $dump .= 'INSERT INTO "'.$table->name.'" ("'
                    .implode('", "', $columns).'") VALUES ('.implode(', ', $values).");\n";
            }
        }

        $dump .= "COMMIT;\n";

        $target = $dir."/{$prefix}_{$stamp}.sql";
        file_put_contents($target, $dump);

        return $target;
    }

    /**
     * @return array{name: string, bytes: int}
     */
    protected function archiveFiles(string $dir, string $stamp): array
    {
        $this->ensureDirectory($dir);

        $root = (string) config('filesystems.disks.public.root');
        $this->ensureDirectory($root); // fresh installs may not have it yet

        $archive = $dir."/files_{$stamp}.tar.gz";

        // Everything on the public disk (uploads/, logos/,
        // company-logos/, profile-photos/…) — restored by extracting
        // the archive under storage/app.
        $result = Process::timeout(3600)->run(
            'tar -czf '.escapeshellarg($archive)
            .' -C '.escapeshellarg(dirname($root))
            .' '.escapeshellarg(basename($root)),
        );

        if (! $result->successful() || ! is_file($archive) || filesize($archive) === 0) {
            @unlink($archive);
            throw new \RuntimeException('Archiving stored files failed: '.trim($result->errorOutput()));
        }

        return ['name' => basename($archive), 'bytes' => (int) filesize($archive)];
    }

    /**
     * Compress a finished dump in place and describe the result.
     *
     * @return array{name: string, bytes: int}
     */
    protected function gzip(string $file): array
    {
        $result = Process::timeout(3600)->run('gzip -f '.escapeshellarg($file));

        if (! $result->successful() || ! is_file($file.'.gz') || filesize($file.'.gz') === 0) {
            @unlink($file.'.gz');
            throw new \RuntimeException('Compressing '.basename($file).' failed: '.trim($result->errorOutput()));
        }

        return ['name' => basename($file.'.gz'), 'bytes' => (int) filesize($file.'.gz')];
    }

    protected function vacuumInto(string $target): string
    {
        // VACUUM INTO needs the filename inlined; escape it accordingly.
        return "VACUUM INTO '".str_replace("'", "''", $target)."'";
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create backup directory: {$dir}");
        }
    }
}

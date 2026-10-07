<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use App\Models\BackupIntegritySnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Source-data integrity checking (Tao head 7): a snapshot is a
 * manifest of the data being backed up — per-file bytes + SHA-256
 * across the public storage disk and per-table row counts for the
 * business tables — plus the diff against the previous snapshot.
 * Corruption or unexpected loss on the source shows up in the diff
 * report before it flows into every backup that follows. Snapshot
 * rows are linked to the archive they accompanied, which is the
 * reference `backups:test-restore` compares a restored database
 * against. The backup feature's own operational tables are excluded:
 * they change with every snapshot, so including them would make
 * every diff report noise.
 */
class IntegrityService
{
    /**
     * Tables whose row counts describe backup operations, not the
     * business data being protected.
     */
    protected const OPERATIONAL_TABLES = [
        'backup_settings',
        'backup_archives',
        'backup_integrity_snapshots',
        'backup_restore_tests',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
    ];

    public function snapshot(?BackupArchive $archive = null): BackupIntegritySnapshot
    {
        $manifest = [
            'generated_at' => now()->toISOString(),
            'db' => $this->tableCounts(),
            'files' => $this->fileManifest(),
        ];

        $previous = BackupIntegritySnapshot::query()->latest('id')->first();
        $diff = $previous ? $this->diff($previous->manifest, $manifest) : null;

        $snapshot = new BackupIntegritySnapshot;
        $snapshot->fill([
            'manifest' => $manifest,
            'diff' => $diff,
            'summary' => $this->summarise($manifest, $diff),
        ]);
        // Ownership FK — assigned explicitly, never mass-assigned.
        $snapshot->backup_archive_id = $archive?->id;
        $snapshot->save();

        return $snapshot;
    }

    /**
     * @return array{tables: array<string, int>, total_rows: int}
     */
    public function tableCounts(): array
    {
        $tables = match (DB::connection()->getDriverName()) {
            'sqlite' => array_column(
                DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
                'name',
            ),
            'mysql' => array_column(
                DB::select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\''),
                'name',
            ),
            default => [],
        };

        $counts = [];
        $total = 0;

        foreach ($tables as $table) {
            if (in_array($table, static::OPERATIONAL_TABLES, true)) {
                continue;
            }

            // Names come from schema introspection, never user input —
            // but they still pass through the identifier allowlist
            // before reaching the query builder, so a subverted
            // introspection query cannot smuggle a crafted name.
            $count = (int) DB::table($this->knownTable($table))->count();
            $counts[(string) $table] = $count;
            $total += $count;
        }

        ksort($counts);

        return ['tables' => $counts, 'total_rows' => $total];
    }

    /**
     * Allowlist-shaped guard for introspected table names: plain
     * identifiers only, anything else is refused rather than skipped
     * — a quiet skip here would hide a corrupted schema read.
     */
    protected function knownTable(mixed $table): string
    {
        $name = (string) $table;

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new \RuntimeException("Refusing a non-identifier table name from schema introspection: {$name}");
        }

        return $name;
    }

    /**
     * @return array{count: int, bytes: int, hashes: array<string, array{b: int, h: string}>}
     */
    public function fileManifest(): array
    {
        $disk = Storage::disk('public');
        $hashes = [];
        $bytes = 0;

        foreach ($disk->allFiles('/') as $path) {
            $size = $disk->size($path);
            $bytes += $size;
            $hashes[$path] = ['b' => $size, 'h' => $this->hashFile($disk, $path)];
        }

        ksort($hashes);

        return ['count' => count($hashes), 'bytes' => $bytes, 'hashes' => $hashes];
    }

    /**
     * Changes between two manifests: files added/removed/content-
     * changed (a size change alone is reported as changed), and
     * tables whose row counts moved.
     */
    public function diff(array $previous, array $current): array
    {
        $oldFiles = $previous['files']['hashes'] ?? [];
        $newFiles = $current['files']['hashes'] ?? [];

        $added = array_diff_key($newFiles, $oldFiles);
        $removed = array_diff_key($oldFiles, $newFiles);
        $changed = [];

        foreach ($newFiles as $path => $meta) {
            if (isset($oldFiles[$path]) && $oldFiles[$path] !== $meta) {
                $changed[] = $path;
            }
        }

        $oldTables = $previous['db']['tables'] ?? [];
        $newTables = $current['db']['tables'] ?? [];

        $tables = [];
        foreach ($newTables as $table => $count) {
            if (array_key_exists($table, $oldTables) && $oldTables[$table] !== $count) {
                $tables[$table] = ['from' => $oldTables[$table], 'to' => $count];
            }
        }
        foreach ($oldTables as $table => $count) {
            if (! array_key_exists($table, $newTables)) {
                $tables[$table] = ['from' => $count, 'to' => null];
            }
        }

        return [
            'files' => [
                'added' => array_keys($added),
                'removed' => array_keys($removed),
                'changed' => $changed,
            ],
            'tables' => $tables,
        ];
    }

    /**
     * One human line for the admin page, e.g. "34 files (12.3 MB) ·
     * 61 tables / 12,345 rows · +1 file, invoices 120→121".
     */
    protected function summarise(array $manifest, ?array $diff): string
    {
        $files = $manifest['files']['count'].' files ('.$this->formatBytes($manifest['files']['bytes']).')';
        $db = count($manifest['db']['tables']).' tables / '.number_format($manifest['db']['total_rows']).' rows';

        if ($diff === null) {
            return $files.' · '.$db.' · first snapshot';
        }

        $changes = count($diff['files']['added']) + count($diff['files']['removed']) + count($diff['files']['changed']);

        $parts = [$files, $db];

        if ($changes > 0) {
            $parts[] = sprintf(
                '%d file change(s): +%d −%d ~%d',
                $changes,
                count($diff['files']['added']),
                count($diff['files']['removed']),
                count($diff['files']['changed']),
            );
        }

        foreach (array_slice($diff['tables'], 0, 3, true) as $table => $move) {
            $parts[] = $table.' '.$move['from'].'→'.($move['to'] ?? 'gone');
        }

        if (count($diff['tables']) > 3) {
            $parts[] = '…';
        }

        return implode(' · ', $parts);
    }

    protected function hashFile($disk, string $path): string
    {
        $stream = $disk->readStream($path);

        try {
            $context = hash_init('sha256');

            while (! feof($stream)) {
                hash_update($context, (string) fread($stream, 1_048_576));
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1).' '.$units[$index];
    }
}

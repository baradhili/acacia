<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The archive inventory (Tao head 6): one BackupArchive row per zip
 * per destination disk, carrying the SHA-256 recorded when the file
 * was first seen. `reconcile()` walks the disks and upserts what is
 * there (hashing anything new or resized, so a checksum always
 * describes the file as landed); `verify()` re-hashes everything and
 * flips statuses — `corrupt` on a checksum mismatch (silent rot of
 * the backups themselves), `missing` when a disk no longer holds a
 * file it used to (theft, cleanup gone wrong, a detached offsite
 * volume). Disks unreachable under PHP's open_basedir restriction are
 * skipped entirely (DiskAccess), and a disk that throws on access
 * (dead offsite credentials, detached volume) is reported as
 * `inaccessible` — unseen is not missing, and neither may kill the
 * page that renders the inventory.
 */
class ArchiveInventory
{
    public function __construct(protected DiskAccess $diskAccess) {}

    /**
     * @return array{new: list<BackupArchive>, missing: list<BackupArchive>, unreachable: list<array{disk: string, root: string, allowed: string}>, inaccessible: list<array{disk: string, error: string}>}
     */
    public function reconcile(): array
    {
        $new = [];
        $present = [];

        $unreachable = array_column(
            $this->diskAccess->unreachableDestinationDisks(),
            null,
            'disk',
        );

        $inaccessible = [];

        foreach ($this->destinationDisks() as $diskName) {
            if (isset($unreachable[$diskName])) {
                continue;
            }

            try {
                $disk = Storage::disk($diskName);
                $prefix = (string) config('backup.backup.name');
                $files = $disk->exists($prefix) ? $disk->files($prefix) : [];
            } catch (Throwable $e) {
                $inaccessible[$diskName] = ['disk' => $diskName, 'error' => (string) Str::limit($e->getMessage(), 200)];

                continue;
            }

            foreach ($files as $file) {
                if (! str_ends_with($file, '.zip')) {
                    continue;
                }

                // Presence is keyed by disk + name — the row's identity
                // columns — so a file moving under a different path
                // never reads as a missing archive.
                $present[$diskName.'|'.basename($file)] = true;
                $bytes = $disk->size($file);

                $archive = BackupArchive::firstOrNew([
                    'disk' => $diskName,
                    'name' => basename($file),
                ]);

                $isNew = ! $archive->exists;
                $resized = ! $isNew && $archive->bytes !== $bytes;
                // A file marked missing that is present again (restored
                // volume, reattached offsite disk) needs re-hashing and
                // its status cleared even when the size is unchanged.
                $returned = ! $isNew && $archive->status === BackupArchive::STATUS_MISSING;

                $archive->path = $file;
                $archive->bytes = $bytes;

                if ($isNew || $resized || $returned) {
                    $archive->sha256 = $this->hash($diskName, $file);
                    $archive->status = BackupArchive::STATUS_OK;
                    $archive->verified_at = now();
                }

                if (! $archive->backed_up_at) {
                    $archive->backed_up_at = Carbon::createFromTimestamp($disk->lastModified($file));
                }

                $archive->save();

                if ($isNew) {
                    $new[] = $archive;
                }
            }
        }

        // Anything inventoried that no reachable disk holds any more.
        $missing = BackupArchive::query()
            ->where('status', '!=', 'missing')
            ->get()
            ->filter(fn (BackupArchive $archive) => ! isset($unreachable[$archive->disk])
                && ! isset($inaccessible[$archive->disk])
                && ! isset($present[$archive->disk.'|'.$archive->name]))
            ->each(fn (BackupArchive $archive) => $archive->update(['status' => 'missing']))
            ->all();

        return [
            'new' => $new,
            'missing' => array_values($missing),
            'unreachable' => array_values($unreachable),
            'inaccessible' => array_values($inaccessible),
        ];
    }

    /**
     * Re-hash every non-missing archive against its recorded
     * checksum. Archives on open_basedir-blocked disks are counted,
     * not verified — their checksums are unknown, not wrong; the
     * same for archives whose disk throws on access.
     *
     * @return array{ok: int, corrupt: int, missing: int, unreachable: int, inaccessible: int}
     */
    public function verify(): array
    {
        $counts = ['ok' => 0, 'corrupt' => 0, 'missing' => 0, 'unreachable' => 0, 'inaccessible' => 0];

        foreach (BackupArchive::query()->where('status', '!=', 'missing')->get() as $archive) {
            if (! $this->diskAccess->diskIsReachable($archive->disk)) {
                $counts['unreachable']++;

                continue;
            }

            try {
                $disk = Storage::disk($archive->disk);

                if (! $disk->exists($archive->path)) {
                    $archive->update(['status' => 'missing', 'verified_at' => now()]);
                    $counts['missing']++;

                    continue;
                }

                $actual = $this->hash($archive->disk, $archive->path);
            } catch (Throwable $e) {
                Log::warning('Backup verify could not access a disk', [
                    'disk' => $archive->disk,
                    'error' => Str::limit($e->getMessage(), 200),
                ]);
                $counts['inaccessible']++;

                continue;
            }

            $corrupt = $archive->sha256 !== null && $actual !== $archive->sha256;

            $archive->update([
                'status' => $corrupt ? 'corrupt' : 'ok',
                'verified_at' => now(),
            ]);

            $counts[$corrupt ? 'corrupt' : 'ok']++;
        }

        return $counts;
    }

    /**
     * Streamed SHA-256 — works on local disks and (later) the s3
     * offsite without loading an archive into memory.
     */
    public function hash(string $diskName, string $path): string
    {
        $stream = Storage::disk($diskName)->readStream($path);

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

    /**
     * @return list<string>
     */
    protected function destinationDisks(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            (array) config('backup.backup.destination.disks'),
        )));
    }
}

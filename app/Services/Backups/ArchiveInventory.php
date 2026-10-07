<?php

namespace App\Services\Backups;

use App\Models\BackupArchive;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The archive inventory (Tao head 6): one BackupArchive row per zip
 * per destination disk, carrying the SHA-256 recorded when the file
 * was first seen. `reconcile()` walks the disks and upserts what is
 * there (hashing anything new or resized, so a checksum always
 * describes the file as landed); `verify()` re-hashes everything and
 * flips statuses — `corrupt` on a checksum mismatch (silent rot of
 * the backups themselves), `missing` when a disk no longer holds a
 * file it used to (theft, cleanup gone wrong, a detached offsite
 * volume).
 */
class ArchiveInventory
{
    /**
     * @return array{new: list<BackupArchive>, missing: list<BackupArchive>}
     */
    public function reconcile(): array
    {
        $new = [];
        $present = [];

        foreach ($this->destinationDisks() as $diskName) {
            $disk = Storage::disk($diskName);
            $prefix = (string) config('backup.backup.name');
            $files = $disk->exists($prefix) ? $disk->files($prefix) : [];

            foreach ($files as $file) {
                if (! str_ends_with($file, '.zip')) {
                    continue;
                }

                $present[$diskName.'|'.$file] = true;
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

        // Anything inventoried that no disk holds any more.
        $missing = BackupArchive::query()
            ->where('status', '!=', 'missing')
            ->get()
            ->filter(fn (BackupArchive $archive) => ! isset($present[$archive->disk.'|'.$archive->path]))
            ->each(fn (BackupArchive $archive) => $archive->update(['status' => 'missing']))
            ->all();

        return ['new' => $new, 'missing' => array_values($missing)];
    }

    /**
     * Re-hash every non-missing archive against its recorded checksum.
     *
     * @return array{ok: int, corrupt: int, missing: int}
     */
    public function verify(): array
    {
        $counts = ['ok' => 0, 'corrupt' => 0, 'missing' => 0];

        foreach (BackupArchive::query()->where('status', '!=', 'missing')->get() as $archive) {
            $disk = Storage::disk($archive->disk);

            if (! $disk->exists($archive->path)) {
                $archive->update(['status' => 'missing', 'verified_at' => now()]);
                $counts['missing']++;

                continue;
            }

            $actual = $this->hash($archive->disk, $archive->path);
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A point-in-time manifest of the source data (Tao head 7): per-file
 * size + SHA-256 across the public storage disk and per-table row
 * counts, plus the diff against the previous snapshot (files
 * added/removed/changed, tables whose counts moved) and a one-line
 * human summary. Silent corruption or unexpected deletions on the
 * source show up here first — before they flow into every backup
 * that follows.
 */
class BackupIntegritySnapshot extends Model
{
    protected $fillable = [
        'manifest',
        'diff',
        'summary',
    ];

    protected $casts = [
        'manifest' => 'array',
        'diff' => 'array',
    ];

    public function backupArchive(): BelongsTo
    {
        return $this->belongsTo(BackupArchive::class);
    }

    /**
     * The reference a restore test compares its imported row counts
     * against: the snapshot taken with that very archive when one is
     * linked, else the closest snapshot at-or-before the archive's
     * time (for archives predating archive-linked snapshots).
     */
    public static function referenceFor(BackupArchive $archive): ?self
    {
        return static::query()
            ->where('backup_archive_id', $archive->id)
            ->first()
            ?? static::nearestTo($archive->backed_up_at ?? now());
    }

    /**
     * The snapshot closest to (and not after) the given time.
     */
    public static function nearestTo(mixed $time): ?self
    {
        return static::query()
            ->where('created_at', '<=', $time)
            ->latest('id')
            ->first();
    }
}

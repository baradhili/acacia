<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The outcome of one `backups:test-restore` run (Tao head 5): the
 * archive it exercised, pass/fail, the checks performed with their
 * values (tables imported, row-count comparison vs the integrity
 * snapshot, PRAGMA integrity_check) and how long it took. The test
 * restores into a scratch database and never touches live data —
 * an untested backup is not a backup.
 */
class BackupRestoreTest extends Model
{
    protected $fillable = [
        'disk',
        'file',
        'status',
        'checks',
        'message',
        'duration_ms',
    ];

    protected $casts = [
        'checks' => 'array',
        'duration_ms' => 'integer',
    ];

    public function backupArchive(): BelongsTo
    {
        return $this->belongsTo(BackupArchive::class);
    }
}

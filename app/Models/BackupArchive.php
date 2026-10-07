<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One backup zip in the inventory — one row per file per destination
 * disk, carrying its size and SHA-256 as recorded when it was first
 * seen. That checksum is the corruption tripwire: `backups:verify`
 * re-hashes every archive and flips status to `corrupt` on a
 * mismatch, and to `missing` when a disk no longer holds the file
 * (Tao head 6 — track every backup unit, detect missing/corrupted
 * copies). GFS retention (config/backup.php) decides how long files
 * live on disk; the row rides along until then.
 */
class BackupArchive extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_CORRUPT = 'corrupt';

    public const STATUS_MISSING = 'missing';

    protected $fillable = [
        'disk',
        'name',
        'path',
        'bytes',
        'sha256',
        'status',
        'backed_up_at',
        'verified_at',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'backed_up_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function restoreTests(): HasMany
    {
        return $this->hasMany(BackupRestoreTest::class);
    }
}

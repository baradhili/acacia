<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Instance-wide backup schedule settings for `backups:run`. Exactly
 * one persisted row, keyed by SINGLETON_KEY under a unique index:
 * BackupSetting::current() fetch-or-creates it atomically so the
 * scheduled command and the admin page always share the same row.
 * Retention is deliberately not here — it is the GFS policy in
 * config/backup.php, not a per-instance admin knob.
 */
class BackupSetting extends Model
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const DEFAULT_FREQUENCY = 'daily';

    /** The only value singleton_key ever holds — the index is the guard. */
    public const SINGLETON_KEY = 'default';

    protected $fillable = [
        'frequency',
        'last_backup_at',
    ];

    protected $casts = [
        'last_backup_at' => 'datetime',
    ];

    public static function current(): self
    {
        try {
            return static::query()->firstOrCreate(
                ['singleton_key' => static::SINGLETON_KEY],
                ['frequency' => static::DEFAULT_FREQUENCY],
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent caller won the insert race — the unique
            // index guarantees their row is the one to read.
            return static::query()->where('singleton_key', static::SINGLETON_KEY)->firstOrFail();
        }
    }

    /**
     * Stamp the last successful backup. Also inserts the settings row
     * the first time a backup runs before any admin has saved one.
     */
    public function recordSuccess(): void
    {
        $this->last_backup_at = now();
        $this->save();
    }
}

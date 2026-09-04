<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Instance-wide backup schedule settings for `backup:create`. One row,
 * created lazily: until an admin saves a change, BackupSetting::current()
 * hands out the code defaults below.
 */
class BackupSetting extends Model
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const DEFAULT_FREQUENCY = 'daily';

    public const DEFAULT_RETENTION = 30;

    protected $fillable = [
        'frequency',
        'retention_count',
        'last_backup_at',
    ];

    protected $casts = [
        'retention_count' => 'integer',
        'last_backup_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::query()->first()
            ?? new static([
                'frequency' => static::DEFAULT_FREQUENCY,
                'retention_count' => static::DEFAULT_RETENTION,
            ]);
    }

    /**
     * Stamp the last successful backup. Also inserts the settings row
     * the first time a backup runs before any admin has saved one.
     */
    public function recordSuccess(): void
    {
        $this->forceFill(['last_backup_at' => now()])->save();
    }
}

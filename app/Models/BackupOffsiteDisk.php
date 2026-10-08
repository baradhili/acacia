<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The offsite backup destination configured from the admin Backups
 * page (the GUI alternative to the env-driven `s3-backups` disk).
 * Exactly one persisted row, keyed by SINGLETON_KEY under a unique
 * index: BackupOffsiteDisk::current() fetch-or-creates it atomically.
 * The credential bundle is an encrypted cast — live secrets never sit
 * in the DB in the clear (APP_KEY is the key; a stolen DB dump alone
 * cannot read them). `enabled` only ever becomes true through the
 * controller's save-and-test flow, so a scheduled backup never aims
 * at an offsite disk the app could not reach on last try.
 */
class BackupOffsiteDisk extends Model
{
    /** Drivers the Backups page offers; adapters resolved via OffsiteDisk. */
    public const DRIVERS = ['s3', 'sftp'];

    /** The only value singleton_key ever holds — the index is the guard. */
    public const SINGLETON_KEY = 'default';

    protected $fillable = [
        'enabled',
        'driver',
        'root',
        'config',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'encrypted:array',
        'last_test_at' => 'datetime',
    ];

    public static function current(): self
    {
        try {
            return static::query()->firstOrCreate(
                ['singleton_key' => static::SINGLETON_KEY],
                ['driver' => 's3', 'enabled' => false],
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent caller won the insert race — the unique
            // index guarantees their row is the one to read.
            return static::query()->where('singleton_key', static::SINGLETON_KEY)->firstOrFail();
        }
    }

    /**
     * Whether the last recorded connection test passed. Null when no
     * test has run (a freshly created row) — treated as not passed by
     * anything that gates on it.
     */
    public function lastTestPassed(): ?bool
    {
        return match ($this->last_test_status) {
            'passed' => true,
            'failed' => false,
            default => null,
        };
    }
}

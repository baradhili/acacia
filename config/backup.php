<?php

use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;

// spatie/laravel-backup drives the backup engine (head 1 of the Tao:
// one complete unit per run — the database dump, everything on the
// public storage disk, and .env — inside one zip). The Acacia wrapper
// `backups:run` adds the admin's frequency due-check, the archive
// inventory + checksums, and the integrity snapshot; restoring is
// `backups:test-restore` (never touches live data) and
// `backups:restore` (live, safety backup first). See
// docs/runbooks/backup-restore.md for the full mapping.

return [

    'backup' => [
        // The unit's name — one zip per run under {disk}/{name}/.
        'name' => env('BACKUP_NAME', 'acacia'),

        'source' => [
            'files' => [
                // The public storage disk (uploads, logos, profile
                // photos — everything Document stores there) plus the
                // environment file, so a restore rebuilds config too.
                // Coverage excludes only what regenerates (framework
                // caches, logs, the backup destinations themselves —
                // spatie auto-excludes its own output).
                'include' => [
                    storage_path('app/public'),
                    base_path('.env'),
                ],
                'exclude' => [
                    storage_path('logs'),
                ],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                // Zip entries stay relative to the project root
                // (storage/app/public/..., .env) so restores can target
                // paths directly instead of unpacking host-absolute
                // names.
                'relative_path' => base_path(),
            ],

            // spatie's sqlite dumper shells out to the sqlite3 CLI,
            // which need not exist on the host — AppServiceProvider
            // registers a native PHP dumper for the sqlite driver
            // (reads through the live PDO connection, so :memory:
            // test databases dump correctly too).
            'databases' => [
                env('DB_CONNECTION', 'sqlite'),
            ],
        ],

        // The zip itself deflates; an inner gzip layer would only
        // complicate the restore leg for no size win.
        'database_dump_compressor' => null,
        'database_dump_file_timestamp_format' => null,
        'database_dump_filename_base' => 'database',
        'database_dump_file_extension' => '',

        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',

            // Separation (head 3): BACKUP_DESTINATION_DISKS is a
            // comma list, e.g. "backups,s3-backups" — every disk gets
            // a full copy, and the offsite leg ships by configuring
            // the s3-backups disk (see config/filesystems.php and the
            // runbook).
            'disks' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('BACKUP_DESTINATION_DISKS', 'backups')),
            ))),

            'continue_on_failure' => false,
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        // Security (head 6): every archive is AES-encrypted at rest;
        // a stolen copy is useless without BACKUP_ARCHIVE_PASSWORD.
        // For a server-side ERP the server necessarily reads its own
        // data — encryption-at-rest of the archives is the enforceable
        // control (the runbook documents key rotation as
        // rotate-the-password-and-back-up-again).
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),
        'encryption' => 'default',

        // Re-open the finished zip and confirm it holds files before
        // trusting the run (head 5's cheap first gate).
        'verify_backup' => true,

        'tries' => 1,
        'retry_delay' => 0,
    ],

    'notifications' => [
        'notifications' => [
            // Failures notify by mail (log mailers in dev just write
            // the log); successes send nothing — a mail per good
            // daily backup is noise that trains people to ignore the
            // channel. Run activity itself goes to log_channel.
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
        ],

        'notifiable' => Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_NOTIFICATION_EMAIL', env('MAIL_FROM_ADDRESS', 'backups@example.com')),
            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
        'discord' => ['webhook_url' => '', 'username' => '', 'avatar_url' => ''],
        'webhook' => ['url' => ''],
    ],

    'log_channel' => null,

    'monitor_backups' => [
        [
            'name' => env('BACKUP_NAME', 'acacia'),
            'disks' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('BACKUP_DESTINATION_DISKS', 'backups')),
            ))),
            // A weekly frequency must not page as stale at 24h.
            'health_checks' => [
                MaximumAgeInDays::class => 7,
                MaximumStorageInMegabytes::class => env('BACKUP_MONITOR_MAX_STORAGE_MB', 5000),
            ],
        ],
    ],

    'cleanup' => [
        'strategy' => DefaultStrategy::class,

        // History (head 4): grandfather-father-son. The spec's example
        // retention: 7 daily / 4 weekly / 12 monthly / 2 yearly —
        // keep_all covers the daily window, and the yearly entries
        // catch month-end stragglers (a pure 12-month monthly window
        // would keep no year-old copy at all).
        'default_strategy' => [
            'keep_all_backups_for_days' => env('BACKUP_KEEP_DAILY', 7),
            'keep_daily_backups_for_days' => env('BACKUP_KEEP_WEEKLY', 28),
            'keep_weekly_backups_for_weeks' => env('BACKUP_KEEP_MONTHLY', 12),
            'keep_monthly_backups_for_months' => env('BACKUP_KEEP_YEARLY', 24),
            'keep_yearly_backups_for_years' => env('BACKUP_KEEP_ARCHIVAL', 2),
            'delete_oldest_backups_when_using_more_megabytes_than' => env('BACKUP_MONITOR_MAX_STORAGE_MB', 5000),
        ],

        'tries' => 1,
        'retry_delay' => 0,
    ],

];

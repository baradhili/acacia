<?php

// Backup & Restore — strings for the reworked Backups page (spatie/
// laravel-backup engine, integrity snapshots, restore tests). The
// page's older labels convert as the screen is touched; everything
// the rework emits goes through here. en is the complete base; en_AU
// overrides only differing keys (none differ yet).
return [
    'heading' => 'Backups',

    // Status cards
    'last_backup' => 'Last Successful Backup',
    'never' => 'never',
    'schedule' => 'Schedule',
    'schedule_help' => 'The scheduler runs daily and the backup itself decides when it is due.',
    'destinations' => 'Destinations',
    'destinations_help' => 'Every destination disk receives a full copy; at least one should be offsite (see the runbook).',
    'offsite_pending' => 'local only — add an offsite disk via BACKUP_DESTINATION_DISKS',
    'encryption' => 'Encryption',
    'encryption_on' => 'enabled (AES)',
    'encryption_off' => 'disabled — set BACKUP_ARCHIVE_PASSWORD',
    'retention' => 'Retention (daily / weekly / monthly / yearly)',
    'last_restore_test' => 'Last Restore Test',
    'no_restore_tests' => 'never — run one now',

    // Actions
    'run_now' => 'Run Backup Now',
    'run_now_help' => 'Creates one complete archive per destination disk — database, stored files and .env — then records its checksum and a source integrity snapshot. Restore procedures are in the backup & restore runbook.',
    'verify_now' => 'Verify Now',
    'verify_now_help' => 'Re-hashes every archive against its recorded checksum and snapshots the source data with a change report.',
    'test_restore' => 'Test Restore Now',
    'test_restore_help' => 'Restores the newest archive into a scratch database and verifies it — live data is never touched. An untested backup is not a backup.',
    'frequency' => 'Frequency',
    'save' => 'Save',

    // Sections
    'archives' => 'Backup Archives',
    'no_archives' => 'No backups yet — run one above or wait for the schedule.',
    'col_disk' => 'Disk',
    'col_file' => 'File',
    'col_size' => 'Size',
    'col_created' => 'Created',
    'col_status' => 'Status',
    'col_verified' => 'Verified',
    'integrity_snapshots' => 'Integrity Snapshots',
    'no_snapshots' => 'No snapshots recorded yet.',
    'col_snapshot' => 'Snapshot',
    'col_changes' => 'Changes Since Previous',
    'first_snapshot' => 'first snapshot',
    'restore_tests' => 'Restore Tests',
    'no_restore_tests_yet' => 'No restore tests recorded yet.',
    'col_result' => 'Result',
    'col_duration' => 'Duration',
    'col_message' => 'Outcome',
    'restore_is_cli' => 'The Test Restore button never touches live data. Live restores are console-only: php artisan backups:restore --file=… --force (a safety backup runs first, with rollback on failure).',

    // Flash messages
    'run_created' => 'Backup created (:count archive(s) recorded, checksummed and snapshotted).',
    'run_already_running' => 'A backup is already running — nothing was created. Try again once it finishes.',
    'run_skipped' => 'The schedule says a backup is not due; use the console (--force) to override.',
    'run_failed' => 'Backup failed — the error was logged. Check the logs, or run `php artisan backups:run --force` from the console for the full output.',
    'verify_clean' => 'All :count archive(s) verified — checksums match and every copy is present.',
    'verify_problems' => 'Problems found: :corrupt corrupt, :missing missing. See the archives table below.',
    'test_restore_passed' => 'Restore test passed on :file — :message',
    'test_restore_failed' => 'Restore test FAILED on :file — :message',
    'settings_saved' => 'Backup schedule saved (:frequency).',
];

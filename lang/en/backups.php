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
    'offsite_pending' => 'local only — configure the offsite disk in the Offsite Destination card below (or via BACKUP_DESTINATION_DISKS)',
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

    // Destination outside PHP's open_basedir paths
    'basedir_warning_title' => 'A backup destination is unreachable under PHP\'s open_basedir restriction',
    'basedir_warning_body' => 'The disk :disk root :root is outside the paths PHP allows (:allowed), so the app can neither read nor write backups there — every backup, verification and restore test involving it fails until one of the fixes below is applied.',
    'basedir_option_env' => 'Move the destination inside the allowed paths: point BACKUP_PATH at a directory under one of them — e.g. the default storage/app/backups under the app root, or an external volume mounted under it — then clear the config cache.',
    'basedir_option_ini' => 'Or keep the location and widen PHP: add the backup root to open_basedir in the php.ini both php-fpm and the CLI use, then restart php-fpm.',
    'run_failed_unreachable' => 'Backup not started: a destination disk\'s root is outside the paths PHP\'s open_basedir restriction allows — see the configuration warning on this page for the two fixes.',
    'verify_unreachable' => ':count archive(s) sit on a disk unreachable under PHP\'s open_basedir restriction and were not verified — see the configuration warning on this page.',
    'test_restore_disk_unreachable' => 'its disk :disk is unreachable under PHP\'s open_basedir restriction — fix the disk root or open_basedir before this archive can be tested.',

    // Destination that throws on access (dead credentials, detached volume)
    'inaccessible_warning_title' => 'A backup destination could not be reached',
    'inaccessible_warning_body' => 'The disk :disk failed with ":error" when this page listed it. Its archives below come from the inventory and were deliberately not marked missing — check the destination\'s credentials and reachability (the offsite card below, or the backup & restore runbook).',
    'verify_inaccessible' => ':count archive(s) sit on a disk that could not be reached (connection or credentials failed) and were not verified — see the warning on this page.',

    // Offsite destination (GUI)
    'offsite_heading' => 'Offsite Destination',
    'offsite_help' => 'Every backup also lands on this remote disk (separation — an offsite copy survives the app disk entirely). Credentials are encrypted at rest; saving always runs a connection test, and the destination only becomes active once that test passes.',
    'offsite_driver' => 'Driver',
    'offsite_driver_s3' => 'Amazon S3 / S3-compatible',
    'offsite_driver_sftp' => 'SFTP',
    'offsite_driver_unavailable' => 'not installed, run `:install`',
    'offsite_enabled' => 'Enable as a backup destination',
    'offsite_root' => 'Path / prefix',
    'offsite_root_help' => 'SFTP directory (e.g. /srv/backups/acacia) or S3 key prefix — optional.',
    'offsite_save' => 'Save & Test Connection',
    'offsite_last_test' => 'Last connection test: :status (:when)',
    'offsite_last_test_passed' => 'passed',
    'offsite_last_test_failed' => 'failed',
    'offsite_secret_keep' => 'leave blank to keep the stored value',
    'offsite_f_key' => 'Access key ID',
    'offsite_f_secret' => 'Secret access key',
    'offsite_f_region' => 'Region',
    'offsite_f_bucket' => 'Bucket',
    'offsite_f_endpoint' => 'Endpoint (S3-compatible)',
    'offsite_f_path_style' => 'Use path-style endpoints',
    'offsite_f_host' => 'Host',
    'offsite_f_port' => 'Port',
    'offsite_f_username' => 'Username',
    'offsite_f_password' => 'Password',
    'offsite_f_private_key' => 'Private key',
    'offsite_f_private_key_help' => 'OpenSSH private key, if the server authenticates by key',
    'offsite_driver_missing' => 'The :driver adapter package is not installed — run `:install` on the server first.',
    'offsite_probe_passed' => 'connection test passed — a probe file was written, read back and removed on the destination.',
    'offsite_probe_failed' => 'connection test failed: :error',
    'offsite_probe_corrupt' => 'the probe file was written but read back differently — the destination is not behaving like storage.',
    'offsite_missing_fields' => 'The offsite destination still needs: :fields.',
    'offsite_enable_refused' => 'Configuration saved, but the connection test failed so the destination was NOT enabled: :error',
    'offsite_saved_enabled' => 'Offsite destination saved and enabled — the connection test passed.',
    'offsite_saved_disabled' => 'Offsite destination saved (not enabled) — the connection test passed.',
    'offsite_unavailable_warning' => 'The offsite destination is enabled but its adapter package is missing on this server, so backups are running local-only. Install the package or disable the destination below.',

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

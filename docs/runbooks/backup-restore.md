# Backup & Restore Runbook

The application's backup feature is built on
[spatie/laravel-backup](https://github.com/spatie/laravel-backup) and
covers the seven heads of the Tao of Backup. A backup is only as good
as its weakest head, and a restore is only proven by actually testing
it — the feature ships both.

| Tao head | How it is covered |
|---|---|
| 1. Coverage | One complete unit per run: a single zip holding the database dump, **everything** on the public storage disk (uploads, logos, photos), and `.env`. Only caches/logs/framework state are excluded. |
| 2. Frequency | Daily at 04:00 via the scheduler, honouring the admin's frequency setting (daily/weekly/monthly); manual "Run Backup Now"; `backups:run --force` for after-significant-change runs. |
| 3. Separation | Every disk in `BACKUP_DESTINATION_DISKS` receives a full copy — configure the offsite `s3-backups` disk so at least one copy is offsite. |
| 4. History | Grandfather-father-son retention: 7 daily / 4 weekly / 12 monthly / 2 yearly (env-tunable, see `config/backup.php`). |
| 5. Testing | `backups:test-restore` restores the newest archive into a scratch database and verifies it — live data is never touched. |
| 6. Security | Archives are AES-encrypted (`BACKUP_ARCHIVE_PASSWORD`); every archive is inventoried with a SHA-256 and verified daily — corrupt or missing copies are flagged. |
| 7. Integrity | `backups:verify` snapshots the source (per-file hashes, per-table counts) daily and reports changes since the previous snapshot, so silent corruption is caught before it flows into backups. |

The admin **Backups** page (profile dropdown → Backups) is the control
surface: run, verify, test-restore, schedule, and the archive /
snapshot / restore-test history.

## What a backup contains

One zip per destination disk under `{disk-root}/{BACKUP_NAME}/`
(default name `acacia`), e.g. `/backups/acacia/2026-10-07-04-00-00.zip`:

- `db-dumps/*.sql` — the full database (native PHP sqlite dumper or
  `mysqldump` for MySQL);
- `storage/app/public/**` — the public storage disk, verbatim;
- `.env` — the environment file.

Entries are relative to the project root. When a password is
configured the whole zip is AES-256 encrypted — a stolen copy is
useless without the key.

## Configuration

```dotenv
# Local destination — point at dedicated storage (ideally an
# external volume) so backups survive losing the app disk.
BACKUP_PATH=/backups

# Offsite separation: fill the s3-backups disk and add it to the list
# (requires `composer require league/flysystem-aws-s3-v3`).
BACKUP_DESTINATION_DISKS=backups
BACKUP_S3_KEY=...
BACKUP_S3_SECRET=...
BACKUP_S3_REGION=ap-southeast-2
BACKUP_S3_BUCKET=acacia-backups

# Encryption (strongly recommended in production)
BACKUP_ARCHIVE_PASSWORD=long-random-secret

# Failure notifications
BACKUP_NOTIFICATION_EMAIL=admin@example.com

# Retention — spatie's cascading GFS tiers, defaults shown. Everything
# is kept 7 days; a daily survives 4 weeks; a weekly survives 12
# months; a monthly survives 2 years; then one per year.
BACKUP_KEEP_ALL_DAYS=7
BACKUP_KEEP_DAILY_DAYS=28
BACKUP_KEEP_WEEKLY_WEEKS=52
BACKUP_KEEP_MONTHLY_MONTHS=24
BACKUP_KEEP_YEARLY_YEARS=2
```

The scheduler needs the usual cron entry:

```bash
* * * * * cd /var/www/erp && php artisan schedule:run >> /dev/null 2>&1
```

## Daily operation

- `backups:run` (04:00) — backup + cleanup + inventory + snapshot.
- `backups:verify` (04:30) — re-hash every archive against its
  recorded checksum; snapshot the source and report changes. Corrupt
  or missing archives flip the Backups page red.
- Backups page → **Test Restore Now** (or `backups:test-restore`)
  proves the newest archive restores.

## Restoring

### Test restore (never touches live data)

```bash
php artisan backups:test-restore                 # newest ok archive
php artisan backups:test-restore --file=2026-10-07-04-00-00.zip
```

Extracts the dump, rebuilds it into a scratch sqlite database under
`storage/app/backup-restore/`, runs `PRAGMA integrity_check`, and
compares row counts against the integrity snapshot taken with that
backup. Result and details are recorded on the Backups page.

### Live restore (database)

```bash
php artisan backups:restore                      # lists recent archives, explains
php artisan backups:restore --file=2026-10-07-04-00-00.zip          # dry run
php artisan backups:restore --file=2026-10-07-04-00-00.zip --force  # actually restores
```

The command always takes a **fresh pre-restore backup first** and the
restorer keeps its own safety copy; a failed restore rolls back to
that copy. Any retained archive can be chosen — that is the
point-in-time choice. SQLite restores rebuild into a new database
file and swap it in after validation; because long-running web/queue
processes hold the old file open, **restart them after a restore**
(put the app in maintenance first on a busy system), then run
`backups:test-restore` and `php artisan migrate:status`.

MySQL restores import through the `mysql` CLI with the same
safety-copy-then-verify shape.

### Selective restore (single files)

Zip entries are project-root relative, so one file or subtree comes
back without a full restore (the zip asks for the password when
encrypted):

```bash
unzip -j /backups/acacia/2026-10-07-04-00-00.zip 'storage/app/public/uploads/2026/10/*' -d /tmp/restore
# then copy what you need back under storage/app/public
```

### New server / disaster recovery

1. Provision the host, clone the repository, `composer install`.
2. Restore `.env` from the archive (or rebuild it; the archive's copy
   includes the backup password itself — keep that secret safe).
3. Point `BACKUP_PATH`/disks at the backup location.
4. `php artisan backups:restore --file=<newest>.zip --force`.
5. `composer dump-autoload && php artisan migrate:status`, restart
   workers, spot-check the books.

## Encryption and key rotation

- `BACKUP_ARCHIVE_PASSWORD` encrypts every future archive at rest.
  The restore tooling reads it from the environment automatically.
- If the password is compromised: treat old archives as burnt —
  destroy them at the storage layer, set the new password, run
  `backups:run --force`. Archives cannot be re-encrypted in place;
  they are immutable by design.

## Integrity snapshots (source health)

Each `backups:run` and `backups:verify` records a
`backup_integrity_snapshots` row: per-file SHA-256s across the public
disk and per-table row counts, plus the diff since the previous
snapshot. Investigate unexpected diffs (files removed, tables
shrinking) **before** they propagate into subsequent backups. The
operational `backup_*` tables themselves are excluded from manifests.

## Legacy archives (pre-Oct 2026, `backup:create`)

Older runs left `.sql.gz`/`.sqlite.gz` dumps under `{BACKUP_PATH}/db/`
and `.tar.gz` file archives under `{BACKUP_PATH}/files/`. They are not
in the new inventory; restore them manually:

```bash
# SQLite snapshot (VACUUM INTO)
gunzip -c db/database_*.sqlite.gz > database/database.sqlite
# Textual dump (either engine)
gunzip -c db/erp_*.sql.gz | mysql -u root -p erp
# Files
tar -xzvf files/files_*.tar.gz -C storage/app
```

Archive these directories away once anything depends on them; the new
engine never writes them.

## Verification schedule

| Test | Frequency | How |
|---|---|---|
| Archive checksums + presence | Daily, automatic | `backups:verify` |
| Source integrity snapshot | Daily, automatic | `backups:verify` |
| Scratch restore test | After each backup-worthy change; at least monthly | Backups page → Test Restore Now |
| Full disaster-recovery drill | Annually | New-server runbook above, on a scratch host |

Would you be comfortable erasing your disk right now and restoring
from backup? If not, run a test restore and find out why.

<?php

namespace Tests\Feature\Backups;

use App\Models\BackupArchive;
use App\Models\BackupOffsiteDisk;
use App\Models\BackupSetting;
use App\Models\User;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\DiskAccess;
use App\Services\Backups\OffsiteDisk;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The spatie-driven backup engine: admin gating of the Backups page,
 * schedule settings, the singleton settings row, and the
 * backups:run flow — one complete zip per destination disk, the
 * archive inventory with checksums, and the post-run integrity
 * snapshot. The backup destination and the public storage disk are
 * redirected to temp directories so real storage is never touched.
 * Destination roots outside PHP's open_basedir paths (the DiskAccess
 * guards) are exercised through a faked restriction, so the suite
 * runs on hosts whose own ini is unrestricted.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected string $backupPath;

    protected string $publicPath;

    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->backupPath = sys_get_temp_dir().'/erp-backup-test-'.uniqid();
        $this->publicPath = sys_get_temp_dir().'/erp-public-test-'.uniqid();
        // spatie stages the zip in backup.temporary_directory before
        // copying to the destination disks — redirect it too, or the
        // suite leaves CLI-user-owned dirs in real storage that block
        // the web user's backup runs (mkdir permission denied).
        $this->tempPath = sys_get_temp_dir().'/erp-backup-temp-'.uniqid();
        File::ensureDirectoryExists($this->publicPath);

        // An .env stand-in inside the temp tree: the real one exists on
        // dev machines but a bare CI checkout may not have it. It lives
        // inside the included directory (the real .env sits at the
        // project root, outside the public disk) so it rides along
        // without a second include entry — spatie's verify counts
        // manifest entries against zip entries.
        file_put_contents($this->publicPath.'/.env', "APP_NAME=backup-test\n");

        config([
            'filesystems.disks.backups.root' => $this->backupPath,
            'filesystems.disks.public.root' => $this->publicPath,
            // spatie zips the literal include paths, not the disks, so
            // the redirect has to reach the source list as well.
            'backup.backup.source.files.include' => [$this->publicPath],
            'backup.backup.temporary_directory' => $this->tempPath,
            'backup.backup.password' => null,
        ]);

        $this->resetSpatieConfig();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupPath);
        File::deleteDirectory($this->publicPath);
        File::deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    /**
     * spatie snapshots config('backup') into a scoped Config instance,
     * so runtime overrides in tests need the binding dropped and
     * re-registered to take effect deterministically.
     */
    protected function resetSpatieConfig(): void
    {
        $this->app->forgetInstance(Config::class);
        $this->app->scoped(Config::class, fn () => Config::fromArray(config('backup')));
    }

    protected function admin(): User
    {
        return tap(User::factory()->create())->assignRole('admin');
    }

    protected function staff(): User
    {
        return tap(User::factory()->create())->assignRole('staff');
    }

    /**
     * Pin DiskAccess to a fake open_basedir so the reachability guards
     * fire on hosts whose own ini is unrestricted.
     *
     * @param  list<string>  $allowed
     */
    protected function restrictOpenBasedirTo(array $allowed): void
    {
        $this->app->instance(
            DiskAccess::class,
            new class(implode(PATH_SEPARATOR, $allowed)) extends DiskAccess
            {
                public function __construct(private readonly string $restriction) {}

                protected function openBasedir(): string
                {
                    return $this->restriction;
                }
            },
        );
    }

    public function test_backups_page_is_gated_to_admin(): void
    {
        $this->actingAs($this->staff())->get('/backups')->assertForbidden();

        $this->actingAs($this->admin())
            ->get('/backups')
            ->assertOk()
            ->assertSee(__('backups.run_now'))
            ->assertSee(__('backups.test_restore'))
            // A healthy destination renders no open_basedir warning.
            ->assertDontSeeText(__('backups.basedir_warning_title'));
    }

    /**
     * Point the backups disk at a root the fake restriction excludes —
     * the misconfiguration the guards exist for (BACKUP_PATH outside
     * every open_basedir path). Nothing may resolve that disk
     * afterwards: the adapter itself throws at construction.
     */
    protected function blockBackupsDestination(): void
    {
        config(['filesystems.disks.backups.root' => '/home/bret/backups']);
        $this->restrictOpenBasedirTo([base_path(), sys_get_temp_dir()]);
    }

    protected function blockedArchive(): BackupArchive
    {
        return BackupArchive::create([
            'disk' => 'backups',
            'name' => '2026-10-07-04-00-acacia.zip',
            'path' => 'acacia/2026-10-07-04-00-acacia.zip',
            'bytes' => 1024,
            'sha256' => hash('sha256', 'seed'),
            'status' => BackupArchive::STATUS_OK,
            'backed_up_at' => Carbon::parse('2026-10-07 04:00:00'),
        ]);
    }

    public function test_the_page_warns_when_a_destination_root_is_outside_open_basedir(): void
    {
        $this->blockBackupsDestination();
        $archive = $this->blockedArchive();

        $this->actingAs($this->admin())
            ->get('/backups')
            ->assertOk()
            ->assertSeeText(__('backups.basedir_warning_title'))
            ->assertSeeText(__('backups.basedir_option_ini'))
            ->assertSee('/home/bret/backups');

        // Unseen is not missing: the blocked disk is skipped rather
        // than read as empty, so its archives keep their status.
        $this->assertSame(BackupArchive::STATUS_OK, $archive->fresh()->status);
    }

    public function test_running_a_backup_fails_fast_when_a_destination_is_unreachable(): void
    {
        $this->blockBackupsDestination();

        $this->actingAs($this->admin())
            ->post('/backups/run')
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('error', __('backups.run_failed_unreachable'));

        // spatie was never invoked: no archive, no success timestamp.
        $this->assertDatabaseCount('backup_archives', 0);
        $this->assertNull(BackupSetting::current()->last_backup_at);
    }

    public function test_verify_reports_unreachable_archives_without_marking_them_missing(): void
    {
        $this->blockBackupsDestination();
        $archive = $this->blockedArchive();

        $this->actingAs($this->admin())
            ->post('/backups/verify')
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('error', __('backups.verify_unreachable', ['count' => 1]));

        $this->assertSame(BackupArchive::STATUS_OK, $archive->fresh()->status);
    }

    public function test_a_restore_test_on_an_unreachable_archive_records_a_clear_failure(): void
    {
        $this->blockBackupsDestination();
        $archive = $this->blockedArchive();

        $this->actingAs($this->admin())
            ->post('/backups/test-restore')
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('error', __('backups.test_restore_failed', [
                'file' => $archive->name,
                'message' => __('backups.test_restore_disk_unreachable', ['disk' => 'backups']),
            ]));

        $this->assertDatabaseHas('backup_restore_tests', [
            'status' => 'failed',
            'backup_archive_id' => $archive->id,
        ]);
    }

    public function test_admin_can_update_the_schedule_settings(): void
    {
        $this->actingAs($this->admin())
            ->put('/backups/settings', ['frequency' => 'weekly'])
            ->assertRedirect(route('backups.index'));

        $this->assertDatabaseHas('backup_settings', ['frequency' => 'weekly']);
    }

    public function test_schedule_settings_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->put('/backups/settings', ['frequency' => 'hourly'])
            ->assertSessionHasErrors('frequency');

        $this->assertDatabaseCount('backup_settings', 0);
    }

    public function test_current_fetch_or_creates_one_singleton_row(): void
    {
        $first = BackupSetting::current();

        $this->assertTrue($first->exists);
        $this->assertSame(BackupSetting::DEFAULT_FREQUENCY, $first->frequency);
        $this->assertSame($first->id, BackupSetting::current()->id);
        $this->assertDatabaseCount('backup_settings', 1);
    }

    public function test_running_a_backup_from_the_page_creates_one_archived_unit(): void
    {
        Storage::disk('public')->put('uploads/important.pdf', 'document bytes');

        $this->actingAs($this->admin())
            ->post('/backups/run')
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('success');

        $zips = glob($this->backupPath.'/*/*.zip');
        $this->assertNotEmpty($zips, 'a zip landed on the backups disk');

        $archive = BackupArchive::first();
        $this->assertNotNull($archive);
        $this->assertSame('backups', $archive->disk);
        $this->assertSame(basename($zips[0]), $archive->name);
        $this->assertSame(64, strlen((string) $archive->sha256));
        $this->assertSame(BackupArchive::STATUS_OK, $archive->status);
        $this->assertNotNull(BackupSetting::current()->last_backup_at);

        // The unit is complete: dump + public-disk file + .env inside.
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zips[0]));
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
        $zip->close();
        $this->assertTrue($names->contains(fn ($n) => str_starts_with($n, 'db-dumps/') && str_ends_with($n, '.sql')));
        $this->assertTrue($names->contains(fn ($n) => str_ends_with((string) $n, 'uploads/important.pdf')));
        $this->assertTrue($names->contains(fn ($n) => str_ends_with((string) $n, '.env')));

        // A source snapshot accompanies the archive.
        $this->assertDatabaseHas('backup_integrity_snapshots', ['backup_archive_id' => $archive->id]);
    }

    public function test_is_due_respects_the_configured_frequency(): void
    {
        $runner = app(BackupRunner::class);
        $setting = fn (?string $last, string $frequency) => new BackupSetting([
            'frequency' => $frequency,
            'last_backup_at' => $last,
        ]);

        $this->assertTrue($runner->isDue($setting(null, 'daily')));
        $this->assertFalse($runner->isDue($setting(now(), 'daily')));
        $this->assertTrue($runner->isDue($setting(now()->subDays(2), 'daily')));
        $this->assertFalse($runner->isDue($setting(now()->subDay(), 'weekly')));
        $this->assertTrue($runner->isDue($setting(now()->subDays(8), 'weekly')));
        $this->assertTrue($runner->isDue($setting(now()->subDays(40), 'monthly')));
    }

    public function test_command_skips_when_not_due_and_backs_up_with_force(): void
    {
        BackupSetting::create(['frequency' => 'daily', 'last_backup_at' => now()]);

        $this->artisan('backups:run')
            ->expectsOutputToContain('not due')
            ->assertSuccessful();
        $this->assertDatabaseCount('backup_archives', 0);

        $this->artisan('backups:run', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('backup_archives', 1);
        $this->assertNotEmpty(glob($this->backupPath.'/*/*.zip'));
    }

    public function test_overlapping_runs_report_already_running_instead_of_backing_up(): void
    {
        $lock = Cache::lock('backups:run', 60);
        $this->assertTrue($lock->get());

        $result = app(BackupRunner::class)->run(force: true);

        $this->assertSame('already_running', $result['status']);
        $this->assertDatabaseCount('backup_archives', 0);

        $lock->release();

        $result = app(BackupRunner::class)->run(force: true);

        $this->assertSame('ran', $result['status']);
        $this->assertDatabaseCount('backup_archives', 1);
    }

    public function test_profile_dropdown_links_to_backups_for_admin_only(): void
    {
        $this->actingAs($this->admin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('/backups');

        $this->actingAs($this->staff())
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Backups');
    }

    /**
     * env()'s default only covers an absent key — a blanked
     * `BACKUP_PATH=` would otherwise resolve the disk root to '' and
     * the adapter dies at construction. Missing, blank and null must
     * all collapse to the storage default, while a set value wins.
     */
    public function test_backup_path_falls_back_to_the_storage_default(): void
    {
        $default = storage_path('app/backups');

        $cases = [
            'absent' => ['absent', null, $default],
            'blanked' => ['set', '', $default],
            'null' => ['set', null, $default],
            'set wins' => ['set', '/opt/elsewhere', '/opt/elsewhere'],
        ];

        foreach ($cases as $label => [$mode, $value, $expected]) {
            $snapshot = [
                $_ENV['BACKUP_PATH'] ?? null,
                $_SERVER['BACKUP_PATH'] ?? null,
                getenv('BACKUP_PATH') ?: null,
            ];

            if ($mode === 'absent') {
                unset($_ENV['BACKUP_PATH'], $_SERVER['BACKUP_PATH']);
                putenv('BACKUP_PATH');
            } else {
                $_ENV['BACKUP_PATH'] = $value;
                $_SERVER['BACKUP_PATH'] = $value;
                putenv('BACKUP_PATH='.$value);
            }

            try {
                $disks = require config_path('filesystems.php');

                $this->assertSame($expected, $disks['disks']['backups']['root'], "BACKUP_PATH {$label} resolved the wrong root");
            } finally {
                [$envValue, $serverValue, $putenvValue] = $snapshot;

                if ($envValue === null) {
                    unset($_ENV['BACKUP_PATH']);
                } else {
                    $_ENV['BACKUP_PATH'] = $envValue;
                }

                if ($serverValue === null) {
                    unset($_SERVER['BACKUP_PATH']);
                } else {
                    $_SERVER['BACKUP_PATH'] = $serverValue;
                }

                if ($putenvValue === null) {
                    putenv('BACKUP_PATH');
                } else {
                    putenv('BACKUP_PATH='.$putenvValue);
                }
            }
        }
    }

    public function test_the_offsite_destination_card_renders_for_admin(): void
    {
        $this->actingAs($this->admin())
            ->get('/backups')
            ->assertOk()
            ->assertSeeText(__('backups.offsite_heading'))
            ->assertSeeText(__('backups.offsite_driver_s3'))
            ->assertSeeText(__('backups.offsite_driver_sftp'))
            // A healthy page carries no inaccessible-disk warning.
            ->assertDontSeeText(__('backups.inaccessible_warning_title'));
    }

    public function test_saving_an_offsite_destination_encrypts_credentials_and_enables_it(): void
    {
        $this->app->instance(OffsiteDisk::class, new StubOffsiteDisk(true));

        $this->actingAs($this->admin())
            ->post('/backups/offsite', [
                'driver' => 'sftp',
                'host' => 'offsite.example.test',
                // The string a browser actually submits; the stored
                // config must carry an int (SftpConnectionProvider
                // declares int $port).
                'port' => '22',
                'username' => 'acacia',
                'password' => 'sftp-secret-passphrase',
                'root' => '/srv/backups/acacia',
                'enabled' => '1',
            ])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('success', __('backups.offsite_saved_enabled'));

        $row = BackupOffsiteDisk::current();
        $this->assertTrue($row->enabled);
        $this->assertSame('sftp', $row->driver);
        $this->assertSame(22, $row->config['port']);
        $this->assertSame('sftp-secret-passphrase', $row->config['password']);

        // The credential bundle is encrypted at rest — the raw
        // column never carries the plaintext.
        $this->assertStringNotContainsString(
            'sftp-secret-passphrase',
            (string) DB::table('backup_offsite_disks')->where('id', $row->id)->value('config'),
        );

        // Published into the runtime config for the next run.
        $this->assertSame('offsite.example.test', config('filesystems.disks.offsite.host'));
        $this->assertSame('/srv/backups/acacia', config('filesystems.disks.offsite.root'));
        $this->assertContains('offsite', config('backup.backup.destination.disks'));
    }

    public function test_enabling_an_offsite_destination_is_refused_when_the_test_fails(): void
    {
        $this->app->instance(OffsiteDisk::class, new StubOffsiteDisk(false));

        $this->actingAs($this->admin())
            ->post('/backups/offsite', [
                'driver' => 's3',
                'key' => 'aki',
                'secret' => 'sak',
                'bucket' => 'acacia-backups',
                'enabled' => '1',
            ])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('error', __('backups.offsite_enable_refused', ['error' => 'stubbed connection test']));

        // Saved but parked: enabled stays false and nothing is
        // published, so scheduled backups keep running local-only.
        $row = BackupOffsiteDisk::current();
        $this->assertFalse($row->enabled);
        $this->assertSame('failed', $row->last_test_status);
        $this->assertNotContains('offsite', config('backup.backup.destination.disks'));
    }

    public function test_an_offsite_driver_without_its_adapter_package_is_rejected(): void
    {
        $this->app->instance(OffsiteDisk::class, new StubOffsiteDisk(null, unavailable: ['sftp']));

        $this->actingAs($this->admin())
            ->post('/backups/offsite', [
                'driver' => 'sftp',
                'host' => 'offsite.example.test',
                'username' => 'acacia',
            ])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('error', __('backups.offsite_driver_missing', [
                'driver' => 'sftp',
                'install' => 'composer require league/flysystem-sftp-v3',
            ]));

        $this->assertDatabaseCount('backup_offsite_disks', 0);
    }

    public function test_a_failed_edit_keeps_a_working_offsite_destination_untouched(): void
    {
        // An enabled destination is a known-good config whose secrets
        // the masked form cannot give back — a failing replacement
        // (typo in the new host) must not overwrite it or disable the
        // offsite leg.
        BackupOffsiteDisk::current()->fill([
            'enabled' => true,
            'driver' => 'sftp',
            'root' => '/srv/backups',
            'config' => ['host' => 'good.example.test', 'username' => 'acacia', 'password' => 'good-secret'],
        ])->save();
        // Mirror production, where the boot-time sync published the
        // enabled destination before the admin ever opened the page.
        app(OffsiteDisk::class)->sync();

        $this->app->instance(OffsiteDisk::class, new StubOffsiteDisk(false));

        $this->actingAs($this->admin())
            ->post('/backups/offsite', [
                'driver' => 'sftp',
                'host' => 'typo.example.test',
                'username' => 'acacia',
                'password' => 'replacement-secret',
                'enabled' => '1',
            ])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('error', __('backups.offsite_edit_refused', ['error' => 'stubbed connection test']));

        $row = BackupOffsiteDisk::current();
        $this->assertTrue($row->enabled);
        $this->assertSame('good.example.test', $row->config['host']);
        $this->assertSame('good-secret', $row->config['password']);
        $this->assertNull($row->last_test_status);
        $this->assertContains('offsite', config('backup.backup.destination.disks'));
    }

    /**
     * SftpConnectionProvider declares int $port, while forms and any
     * row saved before the cast existed carry numeric strings — the
     * definition must normalise both, and default to 22.
     */
    public function test_the_sftp_port_is_normalised_to_an_integer_in_the_disk_definition(): void
    {
        $service = app(OffsiteDisk::class);

        $row = BackupOffsiteDisk::current();
        $row->fill(['driver' => 'sftp', 'root' => '/srv/backups']);
        $row->config = ['host' => 'sftp.example.test', 'username' => 'acacia', 'port' => '2222'];

        $this->assertSame(2222, $service->definition($row)['port']);

        $row->config = ['host' => 'sftp.example.test', 'username' => 'acacia'];

        $this->assertSame(22, $service->definition($row)['port']);
    }

    public function test_a_destination_that_throws_on_access_is_reported_not_fatal(): void
    {
        config([
            'filesystems.disks.offsite' => ['driver' => 'no-such-adapter'],
            'backup.backup.destination.disks' => ['backups', 'offsite'],
        ]);

        $archive = BackupArchive::create([
            'disk' => 'offsite',
            'name' => '2026-10-08-04-00-acacia.zip',
            'path' => 'acacia/2026-10-08-04-00-acacia.zip',
            'bytes' => 1024,
            'sha256' => hash('sha256', 'offsite'),
            'status' => BackupArchive::STATUS_OK,
            'backed_up_at' => Carbon::parse('2026-10-08 04:00:00'),
        ]);

        $this->actingAs($this->admin())
            ->get('/backups')
            ->assertOk()
            ->assertSeeText(__('backups.inaccessible_warning_title'));

        // Unseen is not missing: a disk that throws keeps its
        // archives' status intact.
        $this->assertSame(BackupArchive::STATUS_OK, $archive->fresh()->status);

        $this->actingAs($this->admin())
            ->post('/backups/verify')
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('error', __('backups.verify_inaccessible', ['count' => 1]));
    }
}

/**
 * OffsiteDisk with a pinned probe outcome (and optionally pinned
 * unavailable drivers), so the controller's save-and-test flows run
 * without touching a real remote destination.
 */
class StubOffsiteDisk extends OffsiteDisk
{
    public function __construct(
        protected readonly ?bool $probeOk,
        protected readonly array $unavailable = [],
    ) {}

    public function probe(BackupOffsiteDisk $disk): array
    {
        return ['ok' => (bool) $this->probeOk, 'message' => 'stubbed connection test'];
    }

    public function driverAvailable(string $driver): bool
    {
        return ! in_array($driver, $this->unavailable, true);
    }
}

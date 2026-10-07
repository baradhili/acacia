<?php

namespace Tests\Feature\Backups;

use App\Models\BackupArchive;
use App\Models\BackupSetting;
use App\Models\User;
use App\Services\Backups\BackupRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The spatie-driven backup engine: admin gating of the Backups page,
 * schedule settings, the singleton settings row, and the
 * backups:run flow — one complete zip per destination disk, the
 * archive inventory with checksums, and the post-run integrity
 * snapshot. The backup destination and the public storage disk are
 * redirected to temp directories so real storage is never touched.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected string $backupPath;

    protected string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->backupPath = sys_get_temp_dir().'/erp-backup-test-'.uniqid();
        $this->publicPath = sys_get_temp_dir().'/erp-public-test-'.uniqid();
        File::ensureDirectoryExists($this->publicPath);

        config([
            'filesystems.disks.backups.root' => $this->backupPath,
            'filesystems.disks.public.root' => $this->publicPath,
            // spatie zips the literal include paths, not the disks, so
            // the redirect has to reach the source list as well.
            'backup.backup.source.files.include' => [$this->publicPath, base_path('.env')],
            'backup.backup.password' => null,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupPath);
        File::deleteDirectory($this->publicPath);

        parent::tearDown();
    }

    protected function admin(): User
    {
        return tap(User::factory()->create())->assignRole('admin');
    }

    protected function staff(): User
    {
        return tap(User::factory()->create())->assignRole('staff');
    }

    public function test_backups_page_is_gated_to_admin(): void
    {
        $this->actingAs($this->staff())->get('/backups')->assertForbidden();

        $this->actingAs($this->admin())
            ->get('/backups')
            ->assertOk()
            ->assertSee(__('backups.run_now'))
            ->assertSee(__('backups.test_restore'));
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
}

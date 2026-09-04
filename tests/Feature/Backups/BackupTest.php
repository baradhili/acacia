<?php

namespace Tests\Feature\Backups;

use App\Models\BackupSetting;
use App\Models\User;
use App\Services\BackupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The built-in backup feature: admin gating of the Backups page, the
 * schedule settings, retention pruning, and the backup:create
 * command's due-check. Runs against the in-memory SQLite test
 * database, with the backup destination redirected to a temp
 * directory so real archives never touch the app's storage.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected string $backupPath;

    protected BackupService $backups;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->backupPath = sys_get_temp_dir().'/erp-backup-test-'.uniqid();
        config(['backups.path' => $this->backupPath]);

        $this->backups = new BackupService;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupPath);

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
            ->assertSee('Run Backup Now');
    }

    public function test_admin_can_update_the_schedule_settings(): void
    {
        $this->actingAs($this->admin())
            ->put('/backups/settings', ['frequency' => 'weekly', 'retention_count' => 10])
            ->assertRedirect(route('backups.index'));

        $this->assertDatabaseHas('backup_settings', ['frequency' => 'weekly', 'retention_count' => 10]);
    }

    public function test_schedule_settings_are_validated(): void
    {
        $response = $this->actingAs($this->admin())
            ->put('/backups/settings', ['frequency' => 'hourly', 'retention_count' => 0]);

        $response->assertSessionHasErrors(['frequency', 'retention_count']);
        $this->assertDatabaseCount('backup_settings', 0);
    }

    public function test_running_a_backup_from_the_page_creates_archives(): void
    {
        $this->actingAs($this->admin())
            ->post('/backups/run')
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('success');

        $this->assertNotEmpty(glob($this->backupPath.'/db/*.gz'));
        $this->assertNotEmpty(glob($this->backupPath.'/files/*.tar.gz'));
        $this->assertNotNull(BackupSetting::query()->first()->last_backup_at);
    }

    public function test_retention_prunes_oldest_archives_per_type(): void
    {
        File::ensureDirectoryExists($this->backupPath.'/db');
        File::ensureDirectoryExists($this->backupPath.'/files');

        foreach (range(1, 5) as $age) {
            $stamp = now()->subMinutes($age)->format('Ymd_His');
            touch("{$this->backupPath}/db/database_{$stamp}.sql.gz", now()->subMinutes($age)->getTimestamp());
            touch("{$this->backupPath}/files/files_{$stamp}.tar.gz", now()->subMinutes($age)->getTimestamp());
        }

        $removed = $this->backups->prune(2);

        // Keep the two newest of each type, drop the three oldest.
        $this->assertCount(6, $removed);
        $this->assertCount(2, glob($this->backupPath.'/db/*.gz'));
        $this->assertCount(2, glob($this->backupPath.'/files/*.tar.gz'));
        $this->assertFileExists($this->backupPath.'/db/database_'.now()->subMinutes(1)->format('Ymd_His').'.sql.gz');
        $this->assertFileDoesNotExist($this->backupPath.'/db/database_'.now()->subMinutes(5)->format('Ymd_His').'.sql.gz');
    }

    public function test_is_due_respects_the_configured_frequency(): void
    {
        $setting = fn (?Carbon $last, string $frequency) => new BackupSetting([
            'frequency' => $frequency,
            'retention_count' => 30,
            'last_backup_at' => $last,
        ]);

        $this->assertTrue($this->backups->isDue($setting(null, 'daily')));
        $this->assertFalse($this->backups->isDue($setting(now(), 'daily')));
        $this->assertTrue($this->backups->isDue($setting(now()->subDays(2), 'daily')));
        $this->assertFalse($this->backups->isDue($setting(now()->subDay(), 'weekly')));
        $this->assertTrue($this->backups->isDue($setting(now()->subDays(8), 'weekly')));
        $this->assertTrue($this->backups->isDue($setting(now()->subDays(40), 'monthly')));
    }

    public function test_command_skips_when_not_due_and_backs_up_with_force(): void
    {
        BackupSetting::create([
            'frequency' => 'daily',
            'retention_count' => 5,
            'last_backup_at' => now(),
        ]);

        $this->artisan('backup:create')
            ->expectsOutputToContain('Backup not due')
            ->assertSuccessful();
        $this->assertEmpty(glob($this->backupPath.'/db/*'));

        $this->artisan('backup:create', ['--force' => true])
            ->expectsOutputToContain('Backup completed.')
            ->assertSuccessful();

        $this->assertNotEmpty(glob($this->backupPath.'/db/*.gz'));
        $this->assertNotEmpty(glob($this->backupPath.'/files/*.tar.gz'));
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

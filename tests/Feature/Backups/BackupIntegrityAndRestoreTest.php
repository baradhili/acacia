<?php

namespace Tests\Feature\Backups;

use App\Models\BackupArchive;
use App\Models\BackupRestoreTest;
use App\Models\Client;
use App\Models\User;
use App\Services\Backups\ArchiveInventory;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\IntegrityService;
use App\Services\Backups\Restorer;
use App\Services\Backups\RestoreTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Tao heads the engine does not give you for free: archive
 * verification (corrupt + missing detection, head 6), source
 * integrity snapshots with change reports (head 7), the scratch
 * restore test (head 5) and the live restorer's safety-copy/swap/
 * rollback flow (head 8) — plus the encrypted-archive round trip.
 * Destination disks are redirected to temp directories.
 */
class BackupIntegrityAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected string $backupPath;

    protected string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);

        $this->backupPath = sys_get_temp_dir().'/erp-backup-verify-'.uniqid();
        $this->publicPath = sys_get_temp_dir().'/erp-public-verify-'.uniqid();
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
        File::deleteDirectory(storage_path('app/backup-restore'));

        parent::tearDown();
    }

    protected function runBackup(): BackupArchive
    {
        $result = app(BackupRunner::class)->run(force: true);

        $this->assertSame('ran', $result['status'], $result['error'] ?? '');

        return $result['created'][0];
    }

    /**
     * spatie snapshots config('backup') into a scoped Config instance
     * during boot, so runtime overrides need the binding dropped and
     * re-registered to take effect.
     */
    protected function resetSpatieConfig(): void
    {
        $this->app->forgetInstance(Config::class);
        $this->app->scoped(Config::class, fn () => Config::fromArray(config('backup')));
    }

    protected function zipPath(BackupArchive $archive): string
    {
        $path = Storage::disk($archive->disk)->path($archive->path);
        $this->assertFileExists($path);

        return $path;
    }

    public function test_verify_flags_a_tampered_archive_as_corrupt(): void
    {
        $archive = $this->runBackup();
        $path = $this->zipPath($archive);

        // Flip bytes in the middle of the zip — a silent-rot stand-in.
        $bytes = file_get_contents($path);
        $corrupted = substr($bytes, 0, 200).'X'.substr($bytes, 201);
        file_put_contents($path, $corrupted);

        $counts = app(ArchiveInventory::class)->verify();

        $this->assertSame(1, $counts['corrupt']);
        $this->assertSame(BackupArchive::STATUS_CORRUPT, $archive->refresh()->status);
    }

    public function test_verify_flags_a_deleted_archive_as_missing(): void
    {
        $archive = $this->runBackup();

        unlink($this->zipPath($archive));

        $counts = app(ArchiveInventory::class)->verify();

        $this->assertSame(1, $counts['missing']);
        $this->assertSame(BackupArchive::STATUS_MISSING, $archive->refresh()->status);

        // A copy that comes back (restored volume, reattached disk) is
        // re-hashed and cleared even though its size never changed —
        // recreate it directly; zipPath asserts existence.
        touch(Storage::disk($archive->disk)->path($archive->path));

        app(ArchiveInventory::class)->reconcile();

        $this->assertSame(BackupArchive::STATUS_OK, $archive->refresh()->status);
        $this->assertNotNull($archive->verified_at);
    }

    public function test_integrity_snapshots_report_source_changes(): void
    {
        $integrity = app(IntegrityService::class);

        Storage::disk('public')->put('uploads/a.txt', 'one');
        Client::factory()->count(2)->create();

        $first = $integrity->snapshot();
        $this->assertStringContainsString('first snapshot', $first->summary);

        // A file lands, a file's content changes, and a row is added.
        Storage::disk('public')->put('uploads/b.txt', 'two');
        Storage::disk('public')->put('uploads/a.txt', 'one changed');
        Client::factory()->create();

        $second = $integrity->snapshot();

        $this->assertSame(['uploads/b.txt'], $second->diff['files']['added']);
        $this->assertSame(['uploads/a.txt'], $second->diff['files']['changed']);
        $this->assertSame(2, $second->diff['tables']['clients']['from']);
        $this->assertSame(3, $second->diff['tables']['clients']['to']);
        $this->assertStringContainsString('clients 2→3', $second->summary);
    }

    public function test_a_fresh_backup_passes_the_restore_test(): void
    {
        Client::factory()->count(3)->create();
        Storage::disk('public')->put('uploads/doc.pdf', 'pdf bytes');

        $archive = $this->runBackup();

        $test = app(RestoreTester::class)->test($archive);

        $this->assertSame('passed', $test->status, $test->message);
        $this->assertSame('ok', $test->checks['integrity_check']);
        $this->assertTrue($test->checks['row_counts_match']);
        $this->assertSame($archive->id, $test->backup_archive_id);

        // The scratch database is cleaned up afterwards.
        $this->assertEmpty(glob(storage_path('app/backup-restore/*.sqlite')));
    }

    public function test_the_restore_test_fails_cleanly_on_a_corrupt_archive(): void
    {
        $archive = $this->runBackup();

        $bytes = file_get_contents($this->zipPath($archive));
        file_put_contents($this->zipPath($archive), substr($bytes, 0, 50));

        $test = app(RestoreTester::class)->test($archive);

        $this->assertSame('failed', $test->status);
        $this->assertNotSame('', (string) $test->message);
        $this->assertDatabaseCount('backup_restore_tests', 1);
    }

    public function test_a_restore_test_can_be_fired_from_the_admin_page(): void
    {
        $admin = tap(User::factory()->create())->assignRole('admin');
        $this->runBackup();

        $this->actingAs($admin)
            ->post('/backups/test-restore')
            ->assertRedirect(route('backups.index'));

        $this->assertSame('passed', BackupRestoreTest::first()->status);
    }

    public function test_the_restorer_rebuilds_a_database_and_keeps_a_safety_copy(): void
    {
        // A scratch "live" database holding different data from the backup.
        $target = sys_get_temp_dir().'/erp-restore-target-'.uniqid().'.sqlite';
        $pdo = new \PDO('sqlite:'.$target);
        $pdo->exec('CREATE TABLE stale (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO stale (id) VALUES (1)');
        $pdo = null;

        $archive = $this->runBackup();

        try {
            $result = app(Restorer::class)->restore($archive, $target);

            $this->assertSame('sqlite', $result['driver']);
            $this->assertGreaterThan(5, $result['tables']);
            $this->assertFileExists($result['safety_copy']);

            // The rebuilt target carries the backup's tables, not the stale one.
            $check = new \PDO('sqlite:'.$target);
            $tables = $check->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
            )->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertNotContains('stale', $tables);
            $this->assertContains('clients', $tables);
            $check = null;
        } finally {
            @unlink($target);
            foreach (glob($target.'.*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    public function test_a_restore_round_trips_multiline_values_and_constraints(): void
    {
        $notes = "line one\r\nline 'quoted' two\n\ntrailing";
        Client::factory()->create(['notes' => $notes]);

        $archive = $this->runBackup();
        $this->assertSame('passed', app(RestoreTester::class)->test($archive)->status);

        // The restorer swaps onto an existing file; give it a stale one.
        $target = sys_get_temp_dir().'/erp-roundtrip-'.uniqid().'.sqlite';
        file_put_contents($target, 'not a database');

        try {
            app(Restorer::class)->restore($archive, $target);

            $check = new \PDO('sqlite:'.$target);

            // Multiline text survives byte-for-byte (the dump joins
            // lines with char(10), never raw newlines).
            $restored = $check->query('SELECT notes FROM clients LIMIT 1')->fetchColumn();
            $this->assertSame($notes, $restored);

            // Explicit indexes survive — the singleton guard's unique
            // index still enforces after a restore.
            $indexes = $check->query(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL",
            )->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains('backup_settings_singleton_key_unique', $indexes);
            $check = null;
        } finally {
            @unlink($target);
            foreach (glob($target.'.*') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }
    }

    public function test_archives_are_encrypted_when_a_password_is_configured(): void
    {
        config(['backup.backup.password' => 'correct horse battery staple']);
        $this->resetSpatieConfig();

        $archive = $this->runBackup();
        $path = $this->zipPath($archive);

        // Without the password the dump is unreadable; with it, the
        // whole chain — extract, scratch restore, row-count compare —
        // still passes.
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = collect(range(0, $zip->numFiles - 1))
            ->map(fn ($i) => (string) $zip->getNameIndex($i))
            ->filter(fn ($n) => str_starts_with($n, 'db-dumps/') && str_ends_with($n, '.sql'));
        $this->assertTrue($names->isNotEmpty(), 'dump member is listed');
        $this->assertFalse((bool) $zip->getFromName($names->first()), 'encrypted member does not decrypt without the password');
        $zip->close();

        $test = app(RestoreTester::class)->test($archive);
        $this->assertSame('passed', $test->status, $test->message);
    }
}

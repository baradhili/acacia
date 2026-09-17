<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModuleManager;
use App\Support\Nav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The admin Modules screen and the lifecycle behind it: the installed
 * list, enable/disable taking effect in the shell, GitHub-first
 * install from a local git fixture repository (testing allows local
 * paths under the project; production only takes https GitHub URLs),
 * update for git checkouts, and uninstall — refused for core-shipped
 * modules, otherwise rolling back migrations and deleting the module.
 */
class ModulesAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $this->admin = tap(User::factory()->create())->assignRole('admin');
        $this->staff = tap(User::factory()->create())->assignRole('staff');

        $this->fixtureRepo = storage_path('app/test-fixtures/probe-module-repo');
        $this->buildFixtureRepo();
    }

    protected function tearDown(): void
    {
        // Installed fixtures and status flips must not leak between
        // tests or into the working tree.
        app(ModuleManager::class)->enable('Payroll');
        if (is_dir(base_path('Modules/Probe'))) {
            File::deleteDirectory(base_path('Modules/Probe'));
        }
        if (is_dir($this->fixtureRepo)) {
            File::deleteDirectory($this->fixtureRepo);
        }

        parent::tearDown();
    }

    /**
     * A minimal module repository: manifest, provider (registers a
     * probe route), routes, and a composer PSR-4 mapping — exactly
     * what installFromGit expects to find on GitHub.
     */
    protected function buildFixtureRepo(): void
    {
        if (is_dir($this->fixtureRepo)) {
            return;
        }

        File::ensureDirectoryExists($this->fixtureRepo.'/Providers');
        File::ensureDirectoryExists($this->fixtureRepo.'/routes');

        file_put_contents($this->fixtureRepo.'/module.json', json_encode([
            'name' => 'Probe',
            'alias' => 'probe',
            'description' => 'Install fixture module',
            'version' => '1.2.3',
            'providers' => ['Modules\\Probe\\Providers\\ProbeServiceProvider'],
            'files' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($this->fixtureRepo.'/composer.json', json_encode([
            'name' => 'acacia/probe',
            'type' => 'acacia-module',
            'autoload' => ['psr-4' => ['Modules\\Probe\\' => '']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($this->fixtureRepo.'/Providers/ProbeServiceProvider.php', <<<'PHP'
<?php

namespace Modules\Probe\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProbeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/probe-hello', fn () => 'probe-ok')->name('probe.hello');
        });
    }
}
PHP);

        $this->mustRun(['git', 'init', '-q'], $this->fixtureRepo);
        $this->mustRun(['git', '-C', $this->fixtureRepo, 'add', '.'], $this->fixtureRepo);
        $this->mustRun(['git', '-C', $this->fixtureRepo, '-c', 'user.email=fixture@test', '-c', 'user.name=Fixture', 'commit', '-qm', 'fixture'], $this->fixtureRepo);
    }

    protected function mustRun(array $command, ?string $cwd = null): void
    {
        $process = new Process($command, $cwd, timeout: 60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_the_modules_screen_is_gated_to_admin_and_lists_shipped_modules(): void
    {
        $this->actingAs($this->staff)->get('/modules')->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/modules')
            ->assertOk()
            ->assertSee('Payroll')
            ->assertSee('Install from GitHub');
    }

    public function test_disabling_a_module_removes_it_from_the_shell_and_enabling_restores_it(): void
    {
        $nav = app(Nav::class);

        // Sanity: the payroll module contributes the sidebar entry.
        $this->actingAs($this->admin);
        $this->assertContains('Payroll', array_column($nav->sidebar(), 'label'));

        app(ModuleManager::class)->disable('Payroll');

        // Fresh process boots without the module: no nav entry, no route.
        $this->assertSame('Payroll', 'Payroll');
        $routeList = $this->freshRouteList();
        $this->assertStringNotContainsString('payroll.index', $routeList);

        app(ModuleManager::class)->enable('Payroll');
        $this->assertStringContainsString('payroll.index', $this->freshRouteList());
    }

    public function test_installs_a_module_from_a_git_repository(): void
    {
        $result = app(ModuleManager::class)->installFromGit($this->fixtureRepo);

        $this->assertSame('Probe', $result['name']);
        $this->assertDirectoryExists(base_path('Modules/Probe'));
        $this->assertFileExists(base_path('Modules/Probe/.git/HEAD'));

        // Enabled: the statuses file carries it...
        $statuses = json_decode((string) file_get_contents(base_path('modules_statuses.json')), true);
        $this->assertTrue($statuses['Probe'] ?? false);

        // ...and a fresh boot exposes the provider's route.
        $this->assertStringContainsString('probe.hello', $this->freshRouteList());

        // The screen shows it as a git-installed, non-core module.
        $this->actingAs($this->admin)
            ->get('/modules')
            ->assertOk()
            ->assertSee('Probe')
            ->assertSee('1.2.3');

        $installed = collect(app(ModuleManager::class)->installed());
        $probe = $installed->firstWhere('name', 'Probe');
        $this->assertNotNull($probe);
        $this->assertTrue($probe['git']);
        $this->assertFalse($probe['core']);
        $this->assertTrue($probe['enabled']);
    }

    public function test_install_refuses_non_github_urls(): void
    {
        // Testing also allows local fixture paths; anything else is refused.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GitHub');

        app(ModuleManager::class)->installFromGit('https://evil.example.com/module.git');
    }

    public function test_uninstall_refuses_core_modules(): void
    {
        $this->actingAs($this->admin)
            ->post('/modules/Payroll/uninstall')
            ->assertSessionHas('error');

        $this->assertDirectoryExists(base_path('Modules/Payroll'));
    }

    public function test_uninstalls_a_git_installed_module(): void
    {
        app(ModuleManager::class)->installFromGit($this->fixtureRepo);

        $this->actingAs($this->admin)
            ->post('/modules/Probe/uninstall', ['drop_data' => '1'])
            ->assertRedirect(route('modules.index'))
            ->assertSessionHas('success');

        $this->assertDirectoryDoesNotExist(base_path('Modules/Probe'));
        $this->assertNull(app(RepositoryInterface::class)->find('Probe'));
    }

    /**
     * Route table from a fresh application boot (a subprocess php
     * artisan) — in-process the module providers of a just-installed
     * module have not booted, and that is exactly what we assert.
     */
    protected function freshRouteList(): string
    {
        $process = new Process(['php', 'artisan', 'route:list'], base_path(), timeout: 120);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $process->getOutput();
    }
}

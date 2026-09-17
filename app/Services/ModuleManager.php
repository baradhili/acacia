<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Nwidart\Modules\Contracts\RepositoryInterface;
use Nwidart\Modules\Module;
use Symfony\Component\Process\Process;

/**
 * Module lifecycle management behind the admin Modules screen —
 * akaunting's GUI flow adapted to a GitHub-first world: enable/
 * disable through nwidart's own artisan commands, install by cloning
 * a module repository into Modules/ (the per-deployment drop zone),
 * update by fast-forwarding its git checkout, uninstall by rolling
 * back its migrations (optionally dropping its tables) and removing
 * the directory. Modules whose manifest carries "core": true ship
 * with the app and refuse to uninstall.
 */
class ModuleManager
{
    public function __construct(protected RepositoryInterface $modules) {}

    /**
     * Installed modules for the screen: manifest facts plus install
     * source (a .git checkout means git-installed and updatable).
     */
    public function installed(): array
    {
        return collect($this->modules->all())
            ->map(fn (Module $module) => [
                'name' => $module->getName(),
                'alias' => $module->get('alias', strtolower($module->getName())),
                'description' => $module->getDescription(),
                'version' => $module->get('version', '0.0.0'),
                'enabled' => $module->isEnabled(),
                'core' => (bool) $module->get('core', false),
                'git' => is_dir($module->getPath().'/.git'),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function enable(string $name): void
    {
        Artisan::call('module:enable', ['module' => $name]);
    }

    public function disable(string $name): void
    {
        Artisan::call('module:disable', ['module' => $name]);
    }

    /**
     * Install a module from a git repository URL (GitHub first-off).
     * The clone lands in a temp directory, the manifest is validated
     * there, and only then does it move into Modules/ — a failed
     * install leaves nothing behind.
     *
     * @return array{name: string} the installed module's name
     */
    public function installFromGit(string $url): array
    {
        if (! $this->allowedRepositoryUrl($url)) {
            throw new \InvalidArgumentException('Only https GitHub repository URLs can be installed.');
        }

        $temp = storage_path('app/module-install-'.uniqid());
        $this->mustRun(['git', 'clone', '--depth', '1', $url, $temp], "Could not clone {$url}");

        try {
            $manifest = $this->manifestIn($temp);
            $name = $manifest['name'];

            if ($this->modules->find($name) || is_dir(base_path("Modules/{$name}"))) {
                throw new \InvalidArgumentException("A module named {$name} is already present.");
            }

            // A git checkout keeps the module updatable; the temp clone
            // already carries it.
            $target = base_path("Modules/{$name}");
            if (! @rename($temp, $target)) {
                throw new \RuntimeException("Could not move the module into Modules/{$name}.");
            }
        } finally {
            if (is_dir($temp)) {
                File::deleteDirectory($temp);
            }
        }

        $this->dumpAutoload();
        $this->enable($name);
        $this->runArtisan(['module:migrate', $name]);

        return ['name' => $name];
    }

    /**
     * Fast-forward a git-installed module to its latest commit and
     * run any new migrations.
     */
    public function update(string $name): void
    {
        $module = $this->modules->findOrFail($name);

        if (! is_dir($module->getPath().'/.git')) {
            throw new \InvalidArgumentException('Only git-installed modules can be updated.');
        }

        $this->mustRun(['git', '-C', $module->getPath(), 'pull', '--ff-only'], 'Update failed — the module has local changes or the remote moved.');

        $this->dumpAutoload();
        $this->runArtisan(['module:migrate', $name]);
    }

    /**
     * Remove a module: refuse core-shipped manifests, disable, roll
     * back its migrations (dropping its tables when asked), delete
     * the directory, rebuild the autoloader.
     */
    public function uninstall(string $name, bool $dropData = true): void
    {
        $module = $this->modules->findOrFail($name);

        if ((bool) $module->get('core', false)) {
            throw new \InvalidArgumentException('Modules that ship with the app cannot be uninstalled.');
        }

        $this->disable($name);

        if ($dropData) {
            $this->runArtisan(['module:migrate-rollback', $name]);
        }

        File::deleteDirectory($module->getPath());

        $this->dumpAutoload();
    }

    /**
     * Manifest lookup inside a directory: either the root module.json
     * or one level down (a repo containing Modules/<Name>/).
     */
    protected function manifestIn(string $dir): array
    {
        foreach ([$dir.'/module.json', ...glob($dir.'/*/module.json') ?: []] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $manifest = json_decode((string) File::get($path), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('module.json is not valid JSON.');
            }
            foreach (['name', 'version'] as $key) {
                if (empty($manifest[$key])) {
                    throw new \InvalidArgumentException("module.json is missing its {$key}.");
                }
            }
            if (empty($manifest['providers'])) {
                throw new \InvalidArgumentException('module.json declares no providers.');
            }

            return $manifest;
        }

        throw new \InvalidArgumentException('No module.json found in the repository.');
    }

    /**
     * GitHub https URLs, plus local paths under the project only in
     * non-production environments (hermetic tests clone a local
     * fixture repository).
     */
    protected function allowedRepositoryUrl(string $url): bool
    {
        if (preg_match('#^https://github\.com/[\w.\-/]+(\.git)?$#i', $url)) {
            return true;
        }

        return app()->environment('testing') && str_starts_with(realpath($url) ?: '', base_path());
    }

    /**
     * Artisan in a fresh process — see installFromGit for why module
     * migrations must not share the caller's transaction.
     */
    protected function runArtisan(array $command): void
    {
        $this->mustRun(['php', 'artisan', ...$command], 'Module migration failed.');
    }

    protected function dumpAutoload(): void
    {
        $this->mustRun(['composer', 'dump-autoload', '--no-interaction'], 'Could not rebuild the autoloader.');
    }

    protected function mustRun(array $command, string $message): void
    {
        $process = new Process($command, base_path(), timeout: 300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($message.' '.$process->getErrorOutput());
        }
    }
}

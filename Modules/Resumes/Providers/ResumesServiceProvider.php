<?php

namespace Modules\Resumes\Providers;

use App\Support\Nav;
use Illuminate\Support\ServiceProvider;
use Modules\Resumes\Console\Commands\FetchResumeSchemaCommand;

/**
 * The Resumes module's boot: routes, views, migrations, its config
 * (exposed under the top-level 'resumes' key) and its contribution
 * to the shell — the sidebar Resumes entry, visible to every
 * signed-in user.
 */
class ResumesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'resumes');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views resolve by their feature-namespaced names
        // ('resumes.index') via the added location; the 'resumes::'
        // namespace exists for explicit module references.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'resumes');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        $this->commands([
            FetchResumeSchemaCommand::class,
        ]);

        $nav = $this->app->make(Nav::class);
        $nav->addSidebar($this->sidebarItems());
        // The Employees section Payroll owns; no-op when Payroll is
        // disabled, since the dropdown then never exists.
        $nav->addTopbarChild('Employees', $this->employeesResumesItem());
    }

    protected function sidebarItems(): array
    {
        return [
            // No 'roles' key: resumes are deliberately visible to all
            // authenticated users, unlike the payroll data below them.
            ['type' => 'link', 'label' => 'Resumes', 'route' => 'resumes.index', 'active' => ['resumes.*'],
                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
                'add' => 'resumes.create', 'addTitle' => 'Upload Resume', 'position' => 26],
        ];
    }

    protected function employeesResumesItem(): array
    {
        return [
            'type' => 'link', 'label' => 'Resumes', 'route' => 'resumes.index', 'active' => ['resumes.*'],
        ];
    }
}

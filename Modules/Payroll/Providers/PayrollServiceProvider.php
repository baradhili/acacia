<?php

namespace Modules\Payroll\Providers;

use App\Support\Nav;
use Illuminate\Support\ServiceProvider;

/**
 * The Payroll module's boot: routes, views, migrations, its config
 * (exposed under the top-level 'payroll' key exactly as before the
 * module existed, so PayrollService's config reads are untouched) and
 * its contributions to the shell — the sidebar Payroll entry and the
 * PSI Assessment item under the Setup dropdown.
 */
class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'payroll');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views resolve by their existing feature-namespaced names
        // ('payroll.index', 'psi.index') via the added location; the
        // 'payroll::' namespace exists for explicit module references.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'payroll');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        $nav = $this->app->make(Nav::class);
        $nav->addSidebar($this->sidebarItems());
        $nav->addTopbarChild('Setup', $this->setupPsiItem());
    }

    protected function sidebarItems(): array
    {
        return [
            ['type' => 'link', 'label' => 'Payroll', 'route' => 'payroll.index', 'active' => ['payroll.*'],
                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>',
                'add' => 'payroll.runs.create', 'addTitle' => 'New Pay Run',
                'roles' => ['admin', 'accountant'], 'position' => 25],
        ];
    }

    protected function setupPsiItem(): array
    {
        return [
            'type' => 'link', 'label' => 'PSI Assessment', 'route' => 'psi.index',
            'active' => ['psi.*'], 'position' => 67,
        ];
    }
}

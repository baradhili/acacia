<?php

namespace Modules\Skills\Providers;

use App\Support\Nav;
use Illuminate\Support\ServiceProvider;

/**
 * The Skills module's boot: routes, views, migrations and its
 * contribution to the shell — the Skills item in the Employees
 * topbar section, visible to every signed-in user.
 */
class SkillsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views resolve by their feature-namespaced names
        // ('skills.index') via the added location; the 'skills::'
        // namespace exists for explicit module references.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'skills');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        $nav = $this->app->make(Nav::class);
        // The Employees section Payroll owns; no-op when Payroll is
        // disabled, since the dropdown then never exists.
        $nav->addTopbarChild('Employees', $this->employeesSkillsItem());
    }

    protected function employeesSkillsItem(): array
    {
        return [
            // No 'roles' key: the skill library is deliberately
            // visible to all authenticated users — editing it is
            // gated on the routes, not the nav entry.
            'type' => 'link', 'label' => 'Skills', 'route' => 'skills.index', 'active' => ['skills.*'],
        ];
    }
}

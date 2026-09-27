<?php

namespace Modules\Practice\Providers;

use App\Support\Nav;
use App\Support\Widgets;
use Illuminate\Support\ServiceProvider;
use Modules\Practice\Widgets\HoursThisMonthWidget;
use Modules\Practice\Widgets\UnbilledTimeWidget;

/**
 * The Practice module's boot: routes, views and its contributions to
 * the shell — the Time & Projects group inside the core Reports
 * dropdown (positions 10-15, ahead of the IFRS statements) and the
 * hours/unbilled-time dashboard widgets.
 */
class PracticeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views resolve by their module names ('practice.time-by-client')
        // via the added location; the 'practice::' namespace exists for
        // explicit module references.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'practice');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        $nav = $this->app->make(Nav::class);
        $nav->addTopbarChild('Reports', [
            'type' => 'heading', 'label' => 'Time & Projects', 'position' => 10,
        ]);
        $nav->addTopbarChild('Reports', $this->reportsLink('Time by Client', 'reports.time-by-client', 11));
        $nav->addTopbarChild('Reports', $this->reportsLink('Time by Staff', 'reports.time-by-staff', 12));
        $nav->addTopbarChild('Reports', $this->reportsLink('Time by Project', 'reports.time-by-project', 13));
        $nav->addTopbarChild('Reports', $this->reportsLink('Project Timesheet', 'reports.project-timesheet', 14));
        $nav->addTopbarChild('Reports', $this->reportsLink('Project Profitability', 'projects.profitability', 15));

        $widgets = $this->app->make(Widgets::class);
        $widgets->add(HoursThisMonthWidget::class);
        $widgets->add(UnbilledTimeWidget::class, 'md:col-span-1 lg:col-span-2');
    }

    protected function reportsLink(string $label, string $route, int $position): array
    {
        return [
            'type' => 'link', 'label' => $label, 'route' => $route,
            'active' => [$route], 'position' => $position,
        ];
    }
}

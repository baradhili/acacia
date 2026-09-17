<?php

namespace Modules\Crm\Providers;

use App\Support\Nav;
use App\Support\Widgets;
use Illuminate\Support\ServiceProvider;
use Modules\Crm\Widgets\PipelineWidget;

/**
 * The CRM module's boot: migrations, routes, views, and its shell
 * contributions — the Sales section in the sidebar (leads, targets)
 * and the sales-pipeline dashboard widget.
 */
class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views resolve by feature-namespaced names ('crm.leads.index')
        // via the added location; 'crm::' exists for explicit refs.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'crm');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        $nav = $this->app->make(Nav::class);
        $nav->addSidebar([
            ['type' => 'divider', 'position' => 26],
            ['type' => 'heading', 'label' => 'Sales', 'position' => 27],
            ['type' => 'link', 'label' => 'Leads', 'route' => 'crm.leads.index', 'active' => ['crm.leads.*'],
                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>',
                'add' => 'crm.leads.create', 'addTitle' => 'New Lead', 'position' => 28],
            ['type' => 'link', 'label' => 'Sales Targets', 'route' => 'crm.targets.index', 'active' => ['crm.targets.*'],
                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5m0 12h12m3 0h3v-4a3 3 0 00-3-3h-3m-6 4v-4a3 3 0 00-3-3H3m0 8h9M9 3a2 2 0 11-4 0 2 2 0 014 0zm10 4a2 2 0 11-4 0 2 2 0 014 0z"></path>',
                'roles' => ['admin', 'accountant'], 'position' => 29],
        ]);

        $this->app->make(Widgets::class)->add(PipelineWidget::class);
    }
}

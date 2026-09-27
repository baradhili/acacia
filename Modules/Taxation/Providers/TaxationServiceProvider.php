<?php

namespace Modules\Taxation\Providers;

use App\Support\Nav;
use App\Support\Widgets;
use Illuminate\Support\ServiceProvider;
use Modules\Taxation\Widgets\GstPayableWidget;

/**
 * The Taxation module's boot: routes, views and its config — exposed
 * under the top-level 'ato_tax_report' key exactly as before the module
 * existed, so deployments with a published override keep working (the
 * Payroll config precedent) — plus its contributions to the shell: the
 * BAS and Company Tax items under the core Reports dropdown, the BAS
 * Settlements item under the core Accounting dropdown, and the unlodged
 * GST dashboard widget.
 */
class TaxationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'ato_tax_report');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views resolve by their module names ('taxation.bas',
        // 'taxation.settlements') via the added location; the
        // 'taxation::' namespace exists for explicit module references.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'taxation');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        $nav = $this->app->make(Nav::class);
        $nav->addTopbarChild('Reports', $this->reportsBasItem());
        $nav->addTopbarChild('Reports', $this->reportsCompanyTaxItem());
        $nav->addTopbarChild('Accounting', $this->accountingSettlementsItem());

        $this->app->make(Widgets::class)->add(GstPayableWidget::class);
    }

    protected function reportsBasItem(): array
    {
        return [
            'type' => 'link', 'label' => 'BAS (GST)', 'route' => 'reports.bas',
            'active' => ['reports.bas', 'bas-settlements.*'], 'position' => 28,
        ];
    }

    protected function reportsCompanyTaxItem(): array
    {
        return [
            'type' => 'link', 'label' => 'Company Tax Return', 'route' => 'reports.company-tax',
            'active' => ['reports.company-tax'], 'position' => 29,
        ];
    }

    protected function accountingSettlementsItem(): array
    {
        return [
            'type' => 'link', 'label' => 'BAS Settlements', 'route' => 'bas-settlements.index',
            'active' => ['bas-settlements.*'], 'position' => 3,
        ];
    }
}

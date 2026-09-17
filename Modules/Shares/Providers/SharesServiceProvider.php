<?php

namespace Modules\Shares\Providers;

use App\Observers\AuditObserver;
use App\Support\Nav;
use Illuminate\Support\ServiceProvider;
use Modules\Shares\Console\SendDividendStatements;
use Modules\Shares\Models\DividendDeclaration;
use Modules\Shares\Models\DividendDistribution;
use Modules\Shares\Models\FrankingAccountEntry;
use Modules\Shares\Models\Shareholding;

/**
 * The Shares module's boot: routes, views, its console command, the
 * ledger models' audit observers (moved from AppServiceProvider with
 * the models), and its shell contribution — the Shares dropdown in
 * the topbar (shareholders, franking account, dividends). Company
 * identity stays core: CompanyShareholder and ShareClass (the Setup
 * screen's Share Classes) belong to the company profile.
 */
class SharesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views keep their feature-namespaced names ('shareholders.*',
        // 'dividends.*', 'franking-account.*') via the added location.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'shares');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        $this->commands([SendDividendStatements::class]);

        Shareholding::observe(AuditObserver::class);
        FrankingAccountEntry::observe(AuditObserver::class);
        DividendDeclaration::observe(AuditObserver::class);
        DividendDistribution::observe(AuditObserver::class);

        $this->app->make(Nav::class)->addTopbar([
            ['type' => 'dropdown', 'label' => 'Shares', 'position' => 30,
                'roles' => ['admin', 'accountant'],
                'active' => ['shareholders.*', 'franking-account.*', 'dividends.*', 'share-classes.*'], 'children' => [
                    ['type' => 'link', 'label' => 'Shareholders', 'route' => 'shareholders.index', 'active' => ['shareholders.*']],
                    ['type' => 'link', 'label' => 'Franking Account', 'route' => 'franking-account.index', 'active' => ['franking-account.*']],
                    ['type' => 'link', 'label' => 'Dividends', 'route' => 'dividends.index', 'active' => ['dividends.*']],
                ]],
        ]);
    }
}

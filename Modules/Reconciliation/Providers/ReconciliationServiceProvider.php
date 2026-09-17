<?php

namespace Modules\Reconciliation\Providers;

use App\Observers\AuditObserver;
use App\Support\Nav;
use Illuminate\Support\ServiceProvider;
use Modules\Reconciliation\Models\BankTransaction;

/**
 * The Reconciliation module's boot: routes, views, its counterparty
 * rules migration, the bank-transaction audit observer (moved from
 * AppServiceProvider with the model), and its shell contribution —
 * the Banking section in the sidebar.
 */
class ReconciliationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views keep their feature-namespaced names ('reconciliation.*')
        // via the added location; the namespace exists for explicit
        // module references.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'reconciliation');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        BankTransaction::observe(AuditObserver::class);

        $this->app->make(Nav::class)->addSidebar([
            ['type' => 'divider', 'position' => 45],
            ['type' => 'heading', 'label' => 'Banking', 'position' => 50],
            ['type' => 'link', 'label' => 'Bank Reconciliation', 'route' => 'reconciliation.index', 'active' => ['reconciliation.*'],
                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>',
                'position' => 51],
        ]);
    }
}

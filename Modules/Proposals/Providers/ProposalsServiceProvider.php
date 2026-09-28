<?php

namespace Modules\Proposals\Providers;

use App\Support\Nav;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Modules\Proposals\Models\Estimate;

/**
 * The Proposals module's boot: routes, views and its contribution to
 * the shell — the Estimates item in the sidebar's Invoicing section
 * (the position, icon and add-shortcut it carried in CoreNav before
 * the extraction; URLs and route names are unchanged, so bookmarks
 * and the CRM lead hand-off keep working).
 *
 * Also re-pins the polymorphic documents seam: rows written while
 * estimates lived in the core store documentable_type as the legacy
 * 'App\Models\Estimate' class name. The morph-map alias keeps those
 * rows resolving to the moved class and keeps new writes storing the
 * same legacy string (DocumentController builds the type itself), so
 * no data migration is needed for the extraction.
 */
class ProposalsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Views resolve by their feature names ('estimates.index', …)
        // via the added location — unchanged from when they lived in
        // resources/views; 'proposals::' exists for explicit module
        // references.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'proposals');
        $this->app['view']->addLocation(__DIR__.'/../resources/views');

        Relation::morphMap([
            'App\Models\Estimate' => Estimate::class,
        ]);

        $nav = $this->app->make(Nav::class);
        $nav->addSidebar([
            ['type' => 'link', 'label' => 'Estimates', 'route' => 'estimates.index', 'active' => ['estimates.*'],
                'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>',
                'add' => 'estimates.create', 'addTitle' => 'New Estimate', 'position' => 38],
        ]);
    }
}

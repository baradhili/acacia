<?php

namespace App\Providers;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Nav\CoreNav;
use App\Observers\AuditObserver;
use App\Observers\InvoiceObserver;
use App\Observers\TimeEntryObserver;
use App\Services\Backups\NativeSqliteDumper;
use App\Support\Nav;
use App\Support\WidgetLayout;
use App\Support\Widgets;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;

/**
 * Owns the boot-time wiring: the per-IP auth rate limiters
 * (60/min for form views, 5/min for credential posts), the
 * Blade directive @navIcon (the shell's only sanctioned raw echo),
 * CoreNav's registration into the Nav and Widgets singletons,
 * the view composers feeding the navigation/topbar layouts and
 * the dashboard's saved widget layout, and the observers —
 * TimeEntryObserver, InvoiceObserver and AuditObserver on the
 * eight core financial models.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Nav::class);
        $this->app->singleton(Widgets::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The guest auth routes need distinct limiter names: Laravel's
        // default throttle key is only domain|ip, so without names the
        // form views would drain the credential budget of the POSTs
        // (and login would share a bucket with registration).
        RateLimiter::for('auth-forms', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('auth-credentials', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // The only sanctioned raw echo in the shell: nav icons pass
        // Nav::renderIcon's allowlist guard (see its docblock) — views
        // emitting icon markup any other way reintroduce the XSS sink.
        Blade::directive('navIcon', fn (string $expression = '') => "<?php echo \App\Support\Nav::renderIcon({$expression}); ?>");

        // The shell's registration surfaces: core features (and later,
        // module providers) contribute nav sections and dashboard
        // widgets through these registries; the views render whatever
        // is registered, filtered to the viewer's roles.
        CoreNav::register($this->app->make(Nav::class));
        CoreNav::registerWidgets($this->app->make(Widgets::class));

        // spatie/laravel-backup's built-in sqlite dumper shells out to
        // the sqlite3 CLI; this dumper stays inside PHP (and handles
        // :memory: databases), so it takes over the sqlite driver.
        DbDumperFactory::extend('sqlite', fn () => new NativeSqliteDumper);

        View::composer('layouts.navigation', fn ($view) => $view->with('sidebarNav', $this->app->make(Nav::class)->sidebar()));
        View::composer('layouts.topbar', fn ($view) => $view->with('topbarNav', $this->app->make(Nav::class)->topbar()));
        // The dashboard renders the viewer's saved layout (order,
        // visibility, width overrides) over the widget registry; the
        // removed widgets ride along for the edit-mode catalog.
        View::composer('dashboard', function ($view) {
            $layout = $this->app->make(WidgetLayout::class);
            $view->with('dashboardWidgets', $layout->visible(auth()->user()))
                ->with('hiddenWidgets', $layout->hidden(auth()->user()));
        });

        TimeEntry::observe(TimeEntryObserver::class);
        Invoice::observe(InvoiceObserver::class);

        // Register audit observer for all financial models
        Invoice::observe(AuditObserver::class);
        Payment::observe(AuditObserver::class);
        Client::observe(AuditObserver::class);
        Bill::observe(AuditObserver::class);
        BillPayment::observe(AuditObserver::class);
        Project::observe(AuditObserver::class);
        PurchaseOrder::observe(AuditObserver::class);
        TimeEntry::observe(AuditObserver::class);
    }
}

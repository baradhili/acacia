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
use App\Support\Nav;
use App\Support\WidgetLayout;
use App\Support\Widgets;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        // The shell's registration surfaces: core features (and later,
        // module providers) contribute nav sections and dashboard
        // widgets through these registries; the views render whatever
        // is registered, filtered to the viewer's roles.
        CoreNav::register($this->app->make(Nav::class));
        CoreNav::registerWidgets($this->app->make(Widgets::class));

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

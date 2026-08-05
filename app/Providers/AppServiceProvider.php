<?php

namespace App\Providers;

use App\Models\TimeEntry;
use App\Observers\TimeEntryObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TimeEntry::observe(TimeEntryObserver::class);
    }
}

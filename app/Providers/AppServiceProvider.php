<?php

namespace App\Providers;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\TimeEntry;
use App\Observers\AuditObserver;
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
        
        // Register audit observer for all financial models
        Invoice::observe(AuditObserver::class);
        Payment::observe(AuditObserver::class);
        Client::observe(AuditObserver::class);
        Expense::observe(AuditObserver::class);
        Project::observe(AuditObserver::class);
        PurchaseOrder::observe(AuditObserver::class);
        TimeEntry::observe(AuditObserver::class);
        BankTransaction::observe(AuditObserver::class);
    }
}

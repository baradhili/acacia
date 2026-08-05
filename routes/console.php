<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule Wise reconciliation to run daily at 2 AM
Schedule::command('reconcile:wise --days=7')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/wise-reconciliation.log'));

// Schedule PO utilization check to run daily at 6 AM
Schedule::command('po:check-utilization')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/po-utilization.log'));

// Schedule PO activation check to run daily at 1 AM
Schedule::command('po:activate-pending')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/po-activation.log'));

// Schedule overdue invoice check to run daily at 7 AM
Schedule::command('invoices:mark-overdue')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/invoices-overdue.log'));

<?php

use Illuminate\Support\Facades\Route;
use Modules\Crm\Http\Controllers\LeadController;
use Modules\Crm\Http\Controllers\TargetController;

/*
|--------------------------------------------------------------------------
| CRM module routes
|--------------------------------------------------------------------------
| Leads and their funnel are daily sales work (any authenticated
| user); targets are management. 'web' is explicit — provider-loaded
| routes inherit no group.
*/

Route::middleware(['web', 'auth'])->group(function () {
    Route::resource('crm/leads', LeadController::class)->names('crm.leads');
    Route::post('/crm/leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('crm.leads.status');
    Route::post('/crm/leads/{lead}/convert', [LeadController::class, 'convert'])->name('crm.leads.convert');
    Route::post('/crm/leads/{lead}/activities', [LeadController::class, 'storeActivity'])->name('crm.leads.activities.store');
    Route::get('/crm/leads/{lead}/estimate', [LeadController::class, 'estimate'])->name('crm.leads.estimate');
});

Route::middleware(['web', 'auth', 'role:admin|accountant'])->group(function () {
    Route::get('/crm/targets', [TargetController::class, 'index'])->name('crm.targets.index');
    Route::post('/crm/targets', [TargetController::class, 'store'])->name('crm.targets.store');
});

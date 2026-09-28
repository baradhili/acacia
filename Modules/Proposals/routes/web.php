<?php

use Illuminate\Support\Facades\Route;
use Modules\Proposals\Http\Controllers\EstimateController;

// 'web' is explicit: provider-loaded routes inherit no middleware
// group, so without it SubstituteBindings never runs (empty route
// models) and sessions/CSRF drop. URLs and route names are identical
// to the ones the core shipped before the module existed, so
// bookmarks, views and the CRM proposal-stage hand-off keep working.

Route::middleware(['web', 'auth'])->group(function () {
    Route::resource('estimates', EstimateController::class);
    Route::post('/estimates/{estimate}/send', [EstimateController::class, 'send'])->name('estimates.send');
    Route::post('/estimates/{estimate}/accept', [EstimateController::class, 'accept'])->name('estimates.accept');
    Route::post('/estimates/{estimate}/reject', [EstimateController::class, 'reject'])->name('estimates.reject');
    Route::post('/estimates/{estimate}/convert-to-invoice', [EstimateController::class, 'convertToInvoice'])->name('estimates.convertToInvoice');
    Route::post('/estimates/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->name('estimates.duplicate');
});

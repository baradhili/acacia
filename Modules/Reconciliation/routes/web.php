<?php

use Illuminate\Support\Facades\Route;
use Modules\Reconciliation\Http\Controllers\ReconciliationController as ModuleController;

/*
|--------------------------------------------------------------------------
| Reconciliation module routes
|--------------------------------------------------------------------------
| Moved verbatim from routes/web.php; route names unchanged. The 'web'
| group is explicit (provider-loaded routes inherit nothing), which
| keeps SubstituteBindings, sessions and CSRF active.
*/

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/reconciliation', [ModuleController::class, 'index'])->name('reconciliation.index');
    Route::get('/reconciliation/import', [ModuleController::class, 'import'])->name('reconciliation.import');
    Route::post('/reconciliation/import', [ModuleController::class, 'processImport'])->name('reconciliation.process-import');
    Route::post('/reconciliation/auto-match', [ModuleController::class, 'autoMatch'])->name('reconciliation.auto-match');
    Route::get('/reconciliation/transactions/{transaction}/match', [ModuleController::class, 'matchScreen'])->name('reconciliation.match');
    Route::post('/reconciliation/transactions/{transaction}/match', [ModuleController::class, 'storeMatch'])->name('reconciliation.match.store');
    Route::post('/reconciliation/transactions/{transaction}/unmatch', [ModuleController::class, 'unmatch'])->name('reconciliation.unmatch');
    Route::post('/reconciliation/transactions/{transaction}/ignore', [ModuleController::class, 'ignore'])->name('reconciliation.ignore');
});

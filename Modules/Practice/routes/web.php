<?php

use Illuminate\Support\Facades\Route;
use Modules\Practice\Http\Controllers\ProjectProfitabilityController;
use Modules\Practice\Http\Controllers\TimeReportController;

// 'web' is explicit: provider-loaded routes inherit no middleware group,
// so without it SubstituteBindings never runs (empty route models) and
// sessions/CSRF drop. URLs and route names are identical to the ones the
// core shipped before the module existed, so bookmarks and views keep
// working.

// Time reports — visible to every signed-in user, exactly as they were
// as core routes.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/reports/time-by-client', [TimeReportController::class, 'timeByClient'])->name('reports.time-by-client');
    Route::get('/reports/time-by-staff', [TimeReportController::class, 'timeByStaff'])->name('reports.time-by-staff');
    Route::get('/reports/project-timesheet', [TimeReportController::class, 'projectTimesheet'])->name('reports.project-timesheet');

    Route::get('/projects/{project}/profitability', [ProjectProfitabilityController::class, 'profitability'])->name('projects.profitability.show');
});

// The cross-project profitability summary carries the admin/accountant
// gate it had as a core route.
Route::middleware(['web', 'auth', 'role:admin|accountant'])->group(function () {
    Route::get('/reports/project-profitability', [ProjectProfitabilityController::class, 'profitabilityIndex'])->name('projects.profitability');
});

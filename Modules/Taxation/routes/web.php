<?php

use Illuminate\Support\Facades\Route;
use Modules\Taxation\Http\Controllers\BasSettlementController;
use Modules\Taxation\Http\Controllers\TaxReportController;

// 'web' is explicit: provider-loaded routes inherit no middleware group,
// so without it SubstituteBindings never runs (empty route models) and
// sessions/CSRF drop. URLs and route names are identical to the ones the
// core shipped before the module existed, so bookmarks, views and the
// payroll BAS tests keep working.

// Report screens — visible to every signed-in user, exactly as they were
// as core routes (freeze/settle below carry the admin/accountant gate).
// 'entity' refuses entity-less users before the IFRS queries run.
Route::middleware(['web', 'auth', 'entity'])->group(function () {
    Route::get('/reports/gst', [TaxReportController::class, 'gstReport'])->name('reports.gst');
    Route::get('/reports/bas', [TaxReportController::class, 'bas'])->name('reports.bas');
    Route::get('/reports/company-tax', [TaxReportController::class, 'companyTax'])->name('reports.company-tax');
    Route::get('/reports/export/bas/pdf', [TaxReportController::class, 'exportBasPdf'])->name('reports.export.bas.pdf');
    Route::get('/reports/export/company-tax/pdf', [TaxReportController::class, 'exportCompanyTaxPdf'])->name('reports.export.company-tax.pdf');
    Route::get('/reports/export/bas/excel', [TaxReportController::class, 'exportBasExcel'])->name('reports.export.bas.excel');
    Route::get('/reports/export/company-tax/excel', [TaxReportController::class, 'exportCompanyTaxExcel'])->name('reports.export.company-tax.excel');
    Route::get('/reports/export/company-tax/csv', [TaxReportController::class, 'exportCompanyTaxCsv'])->name('reports.export.company-tax.csv');
});

// Lodgement freezing and ATO settlements write to the ledger.
Route::middleware(['web', 'auth', 'entity', 'role:admin|accountant'])->group(function () {
    Route::post('/bas-statements/freeze', [TaxReportController::class, 'freezeBasQuarter'])->name('bas-statements.freeze');
    Route::delete('/bas-statements/{statement}/unfreeze', [TaxReportController::class, 'unfreezeBasQuarter'])->name('bas-statements.unfreeze');

    Route::get('/bas-settlements', [BasSettlementController::class, 'index'])->name('bas-settlements.index');
    Route::post('/bas-settlements', [BasSettlementController::class, 'store'])->name('bas-settlements.store');
    Route::post('/bas-settlements/{settlement}/reverse', [BasSettlementController::class, 'reverse'])->name('bas-settlements.reverse');
});

<?php

use Illuminate\Support\Facades\Route;
use Modules\Payroll\Http\Controllers\PayrollController;
use Modules\Payroll\Http\Controllers\PayrollEmployeeController;
use Modules\Payroll\Http\Controllers\PsiController;

/*
|--------------------------------------------------------------------------
| Payroll module routes
|--------------------------------------------------------------------------
| Moved verbatim from routes/web.php (the enclosing auth + admin/
| accountant groups made explicit here); route names are unchanged so
| links, tests and nav entries keep resolving.
*/

// 'web' is explicit: provider-loaded routes do not inherit the
// group routes/web.php gets, and without it SubstituteBindings never
// runs (controllers receive empty models) and sessions/CSRF drop.
Route::middleware(['web', 'auth', 'role:admin|accountant'])->group(function () {
    // Pay runs (posting actions) and employee master data
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::get('/payroll/runs/create', [PayrollController::class, 'create'])->name('payroll.runs.create');
    Route::post('/payroll/runs', [PayrollController::class, 'store'])->name('payroll.runs.store');
    Route::get('/payroll/runs/{run}', [PayrollController::class, 'show'])->name('payroll.runs.show');
    Route::post('/payroll/runs/{run}/payslips', [PayrollController::class, 'addPayslip'])->name('payroll.runs.payslips.store');
    Route::delete('/payroll/runs/{run}/payslips/{payslip}', [PayrollController::class, 'removePayslip'])->name('payroll.runs.payslips.destroy');
    Route::post('/payroll/runs/{run}/process', [PayrollController::class, 'process'])->name('payroll.runs.process');
    Route::post('/payroll/runs/{run}/reverse', [PayrollController::class, 'reverse'])->name('payroll.runs.reverse');
    Route::delete('/payroll/runs/{run}', [PayrollController::class, 'destroy'])->name('payroll.runs.destroy');
    Route::resource('payroll-employees', PayrollEmployeeController::class)->except(['show'])->names('payroll.employees');

    // PSI assessment: 80% rule, PSB results test, attribution
    Route::get('/psi', [PsiController::class, 'index'])->name('psi.index');
    Route::post('/psi/assess', [PsiController::class, 'assess'])->name('psi.assess');
});

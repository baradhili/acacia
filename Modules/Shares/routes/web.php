<?php

use Illuminate\Support\Facades\Route;
use Modules\Shares\Http\Controllers\DividendDeclarationController;
use Modules\Shares\Http\Controllers\FrankingAccountController;
use Modules\Shares\Http\Controllers\ShareholderController;

/*
|--------------------------------------------------------------------------
| Shares module routes
|--------------------------------------------------------------------------
| Moved verbatim from routes/web.php; route names unchanged. 'web' is
| explicit — provider-loaded routes inherit no group, and without
| SubstituteBindings controllers receive empty models.
*/

Route::middleware(['web', 'auth', 'role:admin|accountant'])->group(function () {
    // Shareholding ledger behind the shareholder registry
    Route::get('/shareholders', [ShareholderController::class, 'index'])->name('shareholders.index');
    Route::get('/shareholders/{shareholder}', [ShareholderController::class, 'show'])->name('shareholders.show');
    Route::post('/shareholders/{shareholder}/shareholdings', [ShareholderController::class, 'storeShareholding'])->name('shareholders.shareholdings.store');
    Route::post('/shareholders/{shareholder}/shareholdings/{shareholding}/cancel', [ShareholderController::class, 'cancelShareholding'])->name('shareholders.shareholdings.cancel');

    Route::get('/franking-account', [FrankingAccountController::class, 'index'])->name('franking-account.index');
    Route::post('/franking-account', [FrankingAccountController::class, 'store'])->name('franking-account.store');
    Route::delete('/franking-account/{entry}', [FrankingAccountController::class, 'destroy'])->name('franking-account.destroy');
    Route::get('/franking-account/disclosure', [FrankingAccountController::class, 'disclosure'])->name('franking-account.disclosure');
    Route::get('/franking-account/disclosure/pdf', [FrankingAccountController::class, 'disclosurePdf'])->name('franking-account.disclosure.pdf');

    Route::get('/dividends', [DividendDeclarationController::class, 'index'])->name('dividends.index');
    Route::get('/dividends/create', [DividendDeclarationController::class, 'create'])->name('dividends.create');
    Route::post('/dividends', [DividendDeclarationController::class, 'store'])->name('dividends.store');
    Route::get('/dividends/{declaration}', [DividendDeclarationController::class, 'show'])->name('dividends.show');
    Route::get('/dividends/{declaration}/edit', [DividendDeclarationController::class, 'edit'])->name('dividends.edit');
    Route::put('/dividends/{declaration}', [DividendDeclarationController::class, 'update'])->name('dividends.update');
    Route::post('/dividends/{declaration}/calculate', [DividendDeclarationController::class, 'calculate'])->name('dividends.calculate');
    Route::post('/dividends/{declaration}/approve', [DividendDeclarationController::class, 'approve'])->name('dividends.approve');
    Route::post('/dividends/{declaration}/record-payment', [DividendDeclarationController::class, 'recordPayment'])->name('dividends.record-payment');
    Route::post('/dividends/{declaration}/send-statements', [DividendDeclarationController::class, 'sendStatements'])->name('dividends.send-statements');
    Route::post('/dividends/{declaration}/cancel', [DividendDeclarationController::class, 'cancel'])->name('dividends.cancel');
    Route::get('/dividends/{declaration}/payment-schedule.csv', [DividendDeclarationController::class, 'paymentScheduleCsv'])->name('dividends.payment-schedule.csv');
    Route::get('/dividends/statements/{distribution}/pdf', [DividendDeclarationController::class, 'statementPdf'])->name('dividends.statements.pdf');
});

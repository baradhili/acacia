<?php

use App\Http\Controllers\BillController;
use App\Http\Controllers\BillPaymentController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ChartOfAccountsController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrepaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\UserController;
use App\Models\Client;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Authenticated routes
Route::middleware('auth')->group(function () {
    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/photo', [ProfileController::class, 'updatePhoto'])->name('profile.photo.update');
    Route::delete('/profile/photo', [ProfileController::class, 'deletePhoto'])->name('profile.photo.delete');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Users (admin only)
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class);
    });

    // Opening balances (admin or accountant)
    Route::middleware('role:admin|accountant')->group(function () {
        Route::get('/opening-balances', [OpeningBalanceController::class, 'index'])->name('opening-balances.index');
        Route::post('/opening-balances', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');

        // Company identity: ABN/TFN, address, directors, shareholders
        Route::get('/company-profile', [CompanyProfileController::class, 'index'])->name('company-profile.index');
        Route::put('/company-profile', [CompanyProfileController::class, 'update'])->name('company-profile.update');

        // Prepaid subscriptions / licences (ledger-posting actions)
        Route::get('/prepayments', [PrepaymentController::class, 'index'])->name('prepayments.index');
        Route::get('/prepayments/{prepayment}', [PrepaymentController::class, 'show'])->name('prepayments.show');
        Route::post('/prepayments/{prepayment}/run', [PrepaymentController::class, 'runNow'])->name('prepayments.run');
        Route::post('/prepayments/{prepayment}/void', [PrepaymentController::class, 'void'])->name('prepayments.void');
        Route::post('/prepayments/amortisations/{amortisation}/reverse', [PrepaymentController::class, 'reverseAmortisation'])->name('prepayments.amortisations.reverse');
    });

    // Clients
    Route::resource('clients', ClientController::class);
    
    // Client Logo
    Route::post('/clients/{client}/logo', [LogoController::class, 'storeClient'])->name('clients.logo.store');
    Route::delete('/clients/{client}/logo', [LogoController::class, 'destroyClient'])->name('clients.logo.destroy');

    // Client Purchase Orders (API)
    Route::get('/clients/{client}/purchase-orders', [ClientController::class, 'purchaseOrders'])->name('clients.purchase-orders');

    // Suppliers (includes vendors via type filter)
    Route::resource('suppliers', SupplierController::class);
    
    // Supplier Logo
    Route::post('/suppliers/{supplier}/logo', [LogoController::class, 'storeSupplier'])->name('suppliers.logo.store');
    Route::delete('/suppliers/{supplier}/logo', [LogoController::class, 'destroySupplier'])->name('suppliers.logo.destroy');

    // Projects
    Route::resource('projects', ProjectController::class);
    Route::post('/projects/{project}/staff/assign', [ProjectController::class, 'assignStaff'])->name('projects.staff.assign');
    Route::delete('/projects/{project}/staff/{user}', [ProjectController::class, 'removeStaff'])->name('projects.staff.remove');
    Route::get('/projects/{project}/profitability', [ProjectController::class, 'profitability'])->name('projects.profitability');

    // Time Entries
    Route::resource('time-entries', TimeEntryController::class);
    Route::post('/time-entries/{timeEntry}/submit', [TimeEntryController::class, 'submit'])->name('time-entries.submit');
    Route::post('/time-entries/{timeEntry}/approve', [TimeEntryController::class, 'approve'])->name('time-entries.approve');
    Route::post('/time-entries/{timeEntry}/reject', [TimeEntryController::class, 'reject'])->name('time-entries.reject');
    Route::get('/timesheets/weekly', [TimeEntryController::class, 'weekly'])->name('timesheets.weekly');
    Route::get('/timesheets/monthly', [TimeEntryController::class, 'monthly'])->name('timesheets.monthly');

    // Purchase Orders
    Route::resource('purchase-orders', PurchaseOrderController::class);
    Route::post('/purchase-orders/{purchaseOrder}/activate', [PurchaseOrderController::class, 'activate'])->name('purchase-orders.activate');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('/purchase-orders/{purchaseOrder}/complete', [PurchaseOrderController::class, 'complete'])->name('purchase-orders.complete');
    Route::post('/purchase-orders/{purchaseOrder}/reopen', [PurchaseOrderController::class, 'reopen'])->name('purchase-orders.reopen');
    Route::post('/purchase-orders/{purchaseOrder}/allocate', [PurchaseOrderController::class, 'allocateTime'])->name('purchase-orders.allocate');
    Route::get('/purchase-orders/{purchaseOrder}/create-invoice', [InvoiceController::class, 'createFromPurchaseOrder'])->name('purchase-orders.create-invoice');

    // Invoices
    Route::resource('invoices', InvoiceController::class);
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{invoice}/unsend', [InvoiceController::class, 'unsend'])->name('invoices.unsend');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('/invoices/{invoice}/record-payment', [InvoiceController::class, 'recordPayment'])->name('invoices.recordPayment');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('/invoices/{invoice}/download-pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.downloadPdf');
    Route::get('/create-invoice-from-time-entries', [InvoiceController::class, 'createFromTimeEntries'])->name('invoices.create-from-time-entries');
    Route::post('/create-invoice-from-time-entries', [InvoiceController::class, 'createFromTimeEntries'])->name('invoices.create-from-time-entries.store');

    // Payments
    Route::resource('payments', PaymentController::class);
    Route::get('/payments/client-invoices/{client}', [PaymentController::class, 'getClientInvoices'])->name('payments.client-invoices');
    Route::post('/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->name('payments.allocate');
    Route::post('/payments/{payment}/remove-allocation/{invoice}', [PaymentController::class, 'removeAllocation'])->name('payments.removeAllocation');
    Route::post('/payments/{payment}/remove-allocations', [PaymentController::class, 'removeAllAllocations'])->name('payments.removeAllAllocations');

    // Credit Notes
    Route::resource('credit-notes', CreditNoteController::class);
    Route::post('/credit-notes/{creditNote}/void', [CreditNoteController::class, 'void'])->name('credit-notes.void');
    Route::get('/credit-notes/create-from-invoice/{invoice}', [CreditNoteController::class, 'createFromInvoice'])->name('credit-notes.create-from-invoice');
    Route::post('/credit-notes/{creditNote}/apply-to-invoice', [CreditNoteController::class, 'applyToInvoice'])->name('credit-notes.applyToInvoice');

    // Bills (accounts payable — invoices from suppliers)
    Route::resource('bills', BillController::class);
    Route::post('/bills/{bill}/open', [BillController::class, 'open'])->name('bills.open');
    Route::post('/bills/{bill}/cancel', [BillController::class, 'cancel'])->name('bills.cancel');
    Route::post('/bills/{bill}/record-payment', [BillController::class, 'recordPayment'])->name('bills.recordPayment');

    // Bill Payments (supplier payments)
    Route::resource('bill-payments', BillPaymentController::class);
    Route::get('/bill-payments/supplier-bills/{supplier}', [BillPaymentController::class, 'getSupplierBills'])->name('bill-payments.supplier-bills');
    Route::post('/bill-payments/{billPayment}/allocate', [BillPaymentController::class, 'allocate'])->name('bill-payments.allocate');
    Route::post('/bill-payments/{billPayment}/remove-allocation/{bill}', [BillPaymentController::class, 'removeAllocation'])->name('bill-payments.removeAllocation');

    // Documents (API)
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/documents/model/{type}/{id}', [DocumentController::class, 'forModel'])->name('documents.for-model');

    // Estimates
    Route::resource('estimates', EstimateController::class);
    Route::post('/estimates/{estimate}/send', [EstimateController::class, 'send'])->name('estimates.send');
    Route::post('/estimates/{estimate}/accept', [EstimateController::class, 'accept'])->name('estimates.accept');
    Route::post('/estimates/{estimate}/reject', [EstimateController::class, 'reject'])->name('estimates.reject');
    Route::post('/estimates/{estimate}/convert-to-invoice', [EstimateController::class, 'convertToInvoice'])->name('estimates.convertToInvoice');
    Route::post('/estimates/{estimate}/duplicate', [EstimateController::class, 'duplicate'])->name('estimates.duplicate');

    // Reports
    Route::get('/reports/time-by-client', [\App\Http\Controllers\ReportController::class, 'timeByClient'])->name('reports.time-by-client');
    Route::get('/reports/time-by-staff', [\App\Http\Controllers\ReportController::class, 'timeByStaff'])->name('reports.time-by-staff');
    Route::get('/reports/time-by-project', [\App\Http\Controllers\ReportController::class, 'timeByProject'])->name('reports.time-by-project');

    // Financial Reports
    Route::get('/reports/trial-balance', [\App\Http\Controllers\ReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/reports/income-statement', [\App\Http\Controllers\ReportController::class, 'incomeStatement'])->name('reports.income-statement');
    Route::get('/reports/balance-sheet', [\App\Http\Controllers\ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('/reports/cash-flow', [\App\Http\Controllers\ReportController::class, 'cashFlowStatement'])->name('reports.cash-flow');

    // Business Reports
    Route::get('/reports/income-by-customer', [\App\Http\Controllers\ReportController::class, 'incomeByCustomer'])->name('reports.income-by-customer');
    Route::get('/reports/expenses-by-category', [\App\Http\Controllers\ReportController::class, 'expensesByCategory'])->name('reports.expenses-by-category');
    Route::get('/reports/aging', [\App\Http\Controllers\ReportController::class, 'agingReport'])->name('reports.aging');
    Route::get('/reports/gst', [\App\Http\Controllers\ReportController::class, 'gstReport'])->name('reports.gst');
    Route::get('/reports/account-statement', [\App\Http\Controllers\ReportController::class, 'accountStatement'])->name('reports.account-statement');
    Route::get('/reports/account-schedule', [\App\Http\Controllers\ReportController::class, 'accountSchedule'])->name('reports.account-schedule');
    Route::get('/reports/bas', [\App\Http\Controllers\ReportController::class, 'bas'])->name('reports.bas');
    Route::get('/reports/company-tax', [\App\Http\Controllers\ReportController::class, 'companyTax'])->name('reports.company-tax');
    Route::get('/reports/export/account-statement/pdf', [\App\Http\Controllers\ReportController::class, 'exportAccountStatementPdf'])->name('reports.export.account-statement.pdf');
    Route::get('/reports/export/bas/pdf', [\App\Http\Controllers\ReportController::class, 'exportBasPdf'])->name('reports.export.bas.pdf');
    Route::get('/reports/export/company-tax/pdf', [\App\Http\Controllers\ReportController::class, 'exportCompanyTaxPdf'])->name('reports.export.company-tax.pdf');
    Route::get('/reports/export/account-statement/excel', [\App\Http\Controllers\ReportController::class, 'exportAccountStatementExcel'])->name('reports.export.account-statement.excel');
    Route::get('/reports/export/bas/excel', [\App\Http\Controllers\ReportController::class, 'exportBasExcel'])->name('reports.export.bas.excel');
    Route::get('/reports/export/company-tax/excel', [\App\Http\Controllers\ReportController::class, 'exportCompanyTaxExcel'])->name('reports.export.company-tax.excel');
    Route::get('/reports/export/company-tax/csv', [\App\Http\Controllers\ReportController::class, 'exportCompanyTaxCsv'])->name('reports.export.company-tax.csv');
    Route::get('/reports/prepayment-schedule', [\App\Http\Controllers\ReportController::class, 'prepaymentSchedule'])->name('reports.prepayment-schedule');
    Route::get('/reports/export/prepayment-schedule/pdf', [\App\Http\Controllers\ReportController::class, 'exportPrepaymentSchedulePdf'])->name('reports.export.prepayment-schedule.pdf');

    // Wise Reconciliation
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::get('/reconciliation/import', [ReconciliationController::class, 'import'])->name('reconciliation.import');
    Route::post('/reconciliation/import', [ReconciliationController::class, 'processImport'])->name('reconciliation.process-import');

    // Chart of Accounts
    Route::get('/chart-of-accounts', [ChartOfAccountsController::class, 'index'])->name('chart-of-accounts.index');
});

require __DIR__.'/auth.php';

// Widget Preferences
Route::middleware(['auth'])->prefix('api/widget-preferences')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\WidgetPreferenceController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\WidgetPreferenceController::class, 'saveAll']);
    Route::put('/', [App\Http\Controllers\Api\WidgetPreferenceController::class, 'update']);
    Route::delete('/reset', [App\Http\Controllers\Api\WidgetPreferenceController::class, 'reset']);
});

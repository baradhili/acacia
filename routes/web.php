<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\PaymentController;
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
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Users (admin only)
    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class);
    });

    // Clients
    Route::resource('clients', ClientController::class);
    
    // Client Logo
    Route::post('/clients/{client}/logo', [LogoController::class, 'storeClient'])->name('clients.logo.store');
    Route::delete('/clients/{client}/logo', [LogoController::class, 'destroyClient'])->name('clients.logo.destroy');

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
    Route::post('/invoices/{invoice}/mark-viewed', [InvoiceController::class, 'markViewed'])->name('invoices.mark-viewed');
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
    Route::post('/payments/{payment}/reallocate-fifo', [PaymentController::class, 'reallocateFifo'])->name('payments.reallocateFifo');

    // Credit Notes
    Route::resource('credit-notes', CreditNoteController::class);
    Route::post('/credit-notes/{creditNote}/void', [CreditNoteController::class, 'void'])->name('credit-notes.void');
    Route::get('/credit-notes/create-from-invoice/{invoice}', [CreditNoteController::class, 'createFromInvoice'])->name('credit-notes.create-from-invoice');
    Route::post('/credit-notes/{creditNote}/apply-to-invoice', [CreditNoteController::class, 'applyToInvoice'])->name('credit-notes.applyToInvoice');

    // Expenses
    Route::resource('expenses', ExpenseController::class);
    Route::post('/expenses/{expense}/submit', [ExpenseController::class, 'submit'])->name('expenses.submit');
    Route::post('/expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
    Route::post('/expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
    Route::post('/expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel');
    Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'downloadReceipt'])->name('expenses.download-receipt');

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
    Route::get('/reports/tax-summary', [\App\Http\Controllers\ReportController::class, 'taxSummary'])->name('reports.tax-summary');
    Route::get('/reports/export/account-statement/pdf', [\App\Http\Controllers\ReportController::class, 'exportAccountStatementPdf'])->name('reports.export.account-statement.pdf');
    Route::get('/reports/export/tax-summary/pdf', [\App\Http\Controllers\ReportController::class, 'exportTaxSummaryPdf'])->name('reports.export.tax-summary.pdf');
    Route::get('/reports/export/account-statement/excel', [\App\Http\Controllers\ReportController::class, 'exportAccountStatementExcel'])->name('reports.export.account-statement.excel');
    Route::get('/reports/export/tax-summary/excel', [\App\Http\Controllers\ReportController::class, 'exportTaxSummaryExcel'])->name('reports.export.tax-summary.excel');

    // Wise Reconciliation
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::get('/reconciliation/import', [ReconciliationController::class, 'import'])->name('reconciliation.import');
    Route::post('/reconciliation/import', [ReconciliationController::class, 'processImport'])->name('reconciliation.process-import');
});

require __DIR__.'/auth.php';

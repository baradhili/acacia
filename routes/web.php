<?php

use App\Http\Controllers\Api\WidgetPreferenceController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BillPaymentController;
use App\Http\Controllers\ChartOfAccountsController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanyProfileController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DividendDeclarationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\FinancialYearController;
use App\Http\Controllers\FrankingAccountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrepaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShareClassController;
use App\Http\Controllers\ShareholderController;
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

        // Domain name registry (intangibles)
        Route::resource('domains', DomainController::class);
        Route::post('/domains/{domain}/amortisation', [DomainController::class, 'createAmortisation'])->name('domains.amortisation');

        // Year-end close workflow (four-eyes hand-off enforced in the service)
        Route::get('/financial-years', [FinancialYearController::class, 'index'])->name('financial-years.index');
        Route::get('/financial-years/{year}/trial', [FinancialYearController::class, 'trial'])->name('financial-years.trial');
        Route::post('/financial-years/{year}/submit', [FinancialYearController::class, 'submit'])->name('financial-years.submit');
        Route::post('/financial-years/{year}/approve', [FinancialYearController::class, 'approve'])->name('financial-years.approve');
        Route::post('/financial-years/{year}/close', [FinancialYearController::class, 'close'])->name('financial-years.close');
        Route::post('/financial-years/{year}/reopen', [FinancialYearController::class, 'reopen'])->name('financial-years.reopen');

        // Shares, franking account & dividends (franking/dividend spec)
        Route::get('/shareholders', [ShareholderController::class, 'index'])->name('shareholders.index');
        Route::get('/shareholders/{shareholder}', [ShareholderController::class, 'show'])->name('shareholders.show');
        Route::post('/shareholders/{shareholder}/shareholdings', [ShareholderController::class, 'storeShareholding'])->name('shareholders.shareholdings.store');
        Route::post('/shareholders/{shareholder}/shareholdings/{shareholding}/cancel', [ShareholderController::class, 'cancelShareholding'])->name('shareholders.shareholdings.cancel');

        Route::resource('share-classes', ShareClassController::class)->except('show');

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
    Route::post('/bills/{bill}/payments/{billPayment}/unapply', [BillController::class, 'unapplyPayment'])->name('bills.unapplyPayment');

    // Bill Payments (supplier payments)
    Route::resource('bill-payments', BillPaymentController::class);
    Route::get('/bill-payments/supplier-bills/{supplier}', [BillPaymentController::class, 'getSupplierBills'])->name('bill-payments.supplier-bills');
    Route::post('/bill-payments/{billPayment}/allocate', [BillPaymentController::class, 'allocate'])->name('bill-payments.allocate');
    Route::post('/bill-payments/{billPayment}/remove-allocation/{bill}', [BillPaymentController::class, 'removeAllocation'])->name('bill-payments.removeAllocation');
    Route::post('/bill-payments/{billPayment}/void', [BillPaymentController::class, 'void'])->name('bill-payments.void');

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
    Route::get('/reports/time-by-client', [ReportController::class, 'timeByClient'])->name('reports.time-by-client');
    Route::get('/reports/time-by-staff', [ReportController::class, 'timeByStaff'])->name('reports.time-by-staff');
    Route::get('/reports/time-by-project', [ReportController::class, 'timeByProject'])->name('reports.time-by-project');

    // Financial Reports
    Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/reports/income-statement', [ReportController::class, 'incomeStatement'])->name('reports.income-statement');
    Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('/reports/cash-flow', [ReportController::class, 'cashFlowStatement'])->name('reports.cash-flow');

    // Business Reports
    Route::get('/reports/income-by-customer', [ReportController::class, 'incomeByCustomer'])->name('reports.income-by-customer');
    Route::get('/reports/expenses-by-category', [ReportController::class, 'expensesByCategory'])->name('reports.expenses-by-category');
    Route::get('/reports/aging', [ReportController::class, 'agingReport'])->name('reports.aging');
    Route::get('/reports/gst', [ReportController::class, 'gstReport'])->name('reports.gst');
    Route::get('/reports/account-statement', [ReportController::class, 'accountStatement'])->name('reports.account-statement');
    Route::get('/reports/account-schedule', [ReportController::class, 'accountSchedule'])->name('reports.account-schedule');
    Route::get('/reports/bas', [ReportController::class, 'bas'])->name('reports.bas');
    Route::get('/reports/company-tax', [ReportController::class, 'companyTax'])->name('reports.company-tax');
    Route::get('/reports/export/account-statement/pdf', [ReportController::class, 'exportAccountStatementPdf'])->name('reports.export.account-statement.pdf');
    Route::get('/reports/export/bas/pdf', [ReportController::class, 'exportBasPdf'])->name('reports.export.bas.pdf');
    Route::get('/reports/export/company-tax/pdf', [ReportController::class, 'exportCompanyTaxPdf'])->name('reports.export.company-tax.pdf');
    Route::get('/reports/export/account-statement/excel', [ReportController::class, 'exportAccountStatementExcel'])->name('reports.export.account-statement.excel');
    Route::get('/reports/export/bas/excel', [ReportController::class, 'exportBasExcel'])->name('reports.export.bas.excel');
    Route::get('/reports/export/company-tax/excel', [ReportController::class, 'exportCompanyTaxExcel'])->name('reports.export.company-tax.excel');
    Route::get('/reports/export/company-tax/csv', [ReportController::class, 'exportCompanyTaxCsv'])->name('reports.export.company-tax.csv');
    Route::get('/reports/prepayment-schedule', [ReportController::class, 'prepaymentSchedule'])->name('reports.prepayment-schedule');
    Route::get('/reports/export/prepayment-schedule/pdf', [ReportController::class, 'exportPrepaymentSchedulePdf'])->name('reports.export.prepayment-schedule.pdf');

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
    Route::get('/', [WidgetPreferenceController::class, 'index']);
    Route::post('/', [WidgetPreferenceController::class, 'saveAll']);
    Route::put('/', [WidgetPreferenceController::class, 'update']);
    Route::delete('/reset', [WidgetPreferenceController::class, 'reset']);
});

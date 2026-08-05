<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\VendorController;
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

    // Clients
    Route::resource('clients', ClientController::class);

    // Suppliers
    Route::resource('suppliers', SupplierController::class);

    // Vendors
    Route::resource('vendors', VendorController::class);

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

    // Reports
    Route::get('/reports/time-by-client', [\App\Http\Controllers\ReportController::class, 'timeByClient'])->name('reports.time-by-client');
    Route::get('/reports/time-by-staff', [\App\Http\Controllers\ReportController::class, 'timeByStaff'])->name('reports.time-by-staff');
    Route::get('/reports/time-by-project', [\App\Http\Controllers\ReportController::class, 'timeByProject'])->name('reports.time-by-project');

    // Wise Reconciliation
    Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
    Route::get('/reconciliation/import', [ReconciliationController::class, 'import'])->name('reconciliation.import');
    Route::post('/reconciliation/import', [ReconciliationController::class, 'processImport'])->name('reconciliation.process-import');
});

require __DIR__.'/auth.php';

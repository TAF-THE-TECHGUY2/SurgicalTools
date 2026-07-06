<?php

use App\Http\Controllers\Api\ApprovalCentreController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeviceUnitController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\HospitalController;
use App\Http\Controllers\Api\InventoryViewController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PastelExportController;
use App\Http\Controllers\Api\PreferenceCardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockCountController;
use App\Http\Controllers\Api\StockItemController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — Surgical Devices ERP (unit-level inventory model)
|--------------------------------------------------------------------------
*/

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    // -- Session / identity -------------------------------------------------
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logoutAll');

    // -- Reference data + global search ------------------------------------
    Route::get('meta/options', MetaController::class)->name('meta.options');
    Route::get('search', GlobalSearchController::class)->name('search');
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // -- Locations (the transfer "entities") --------------------------------
    Route::get('locations/{location}/inventory', [LocationController::class, 'inventory'])->name('locations.inventory');
    Route::apiResource('locations', LocationController::class);

    // -- Inventory views -----------------------------------------------------
    Route::get('inventory/my', [InventoryViewController::class, 'my'])->name('inventory.my');
    Route::get('inventory/item-search', [InventoryViewController::class, 'itemSearch'])->name('inventory.itemSearch');

    // -- Stock catalog + device units ----------------------------------------
    Route::post('stock-items/{stockItem}/units', [StockItemController::class, 'receiveUnits'])->name('stock-items.receive');
    Route::apiResource('stock-items', StockItemController::class);
    Route::put('device-units/{deviceUnit}', [DeviceUnitController::class, 'update'])->name('device-units.update');
    Route::delete('device-units/{deviceUnit}', [DeviceUnitController::class, 'destroy'])->name('device-units.destroy');

    // -- Transfers (unified request → approval flow) ------------------------
    Route::prefix('transfers')->name('transfers.')->group(function () {
        Route::get('/', [TransferController::class, 'index'])->name('index');
        Route::post('/', [TransferController::class, 'store'])->name('store');
        Route::get('{transfer}', [TransferController::class, 'show'])->name('show');
        Route::post('{transfer}/approve', [TransferController::class, 'approve'])->name('approve');
        Route::post('{transfer}/reject', [TransferController::class, 'reject'])->name('reject');
        Route::get('{transfer}/pdf', [TransferController::class, 'downloadPdf'])->name('pdf');
    });

    // -- Stock counts -------------------------------------------------------
    Route::prefix('stock-counts')->name('stock-counts.')->group(function () {
        Route::get('/', [StockCountController::class, 'index'])->name('index');
        Route::post('/', [StockCountController::class, 'store'])->name('store');
        Route::get('{stockCount}', [StockCountController::class, 'show'])->name('show');
        Route::post('{stockCount}/submit', [StockCountController::class, 'submit'])->name('submit');
        Route::post('{stockCount}/photo', [StockCountController::class, 'uploadPhoto'])->name('photo');
        Route::post('{stockCount}/review', [StockCountController::class, 'review'])->name('review');
    });

    // -- Hospitals & doctors ------------------------------------------------
    Route::apiResource('hospitals', HospitalController::class);
    Route::apiResource('doctors', DoctorController::class);

    // -- Preference cards ---------------------------------------------------
    Route::get('preference-cards/{preferenceCard}/print', [PreferenceCardController::class, 'print'])
        ->name('preference-cards.print');
    Route::apiResource('preference-cards', PreferenceCardController::class);

    // -- Admin approval centre ---------------------------------------------
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::get('summary', [ApprovalCentreController::class, 'summary'])->name('summary');
        Route::get('transfers', [ApprovalCentreController::class, 'transfers'])->name('transfers');
        Route::get('counts', [ApprovalCentreController::class, 'counts'])->name('counts');
    });

    // -- Notifications ------------------------------------------------------
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('readAll');
    });

    // -- Reports ------------------------------------------------------------
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('inventory', [ReportController::class, 'inventory'])->name('inventory');
        Route::get('transfers', [ReportController::class, 'transfers'])->name('transfers');
        Route::get('variances', [ReportController::class, 'variances'])->name('variances');
        Route::get('expiry', [ReportController::class, 'expiry'])->name('expiry');
        Route::get('rep-performance', [ReportController::class, 'repPerformance'])->name('repPerformance');
    });

    // -- Pastel export ------------------------------------------------------
    Route::prefix('pastel-exports')->name('pastel.')->group(function () {
        Route::get('/', [PastelExportController::class, 'index'])->name('index');
        Route::post('/', [PastelExportController::class, 'store'])->name('store');
        Route::get('{pastelExport}/download', [PastelExportController::class, 'download'])->name('download');
        Route::post('{pastelExport}/imported', [PastelExportController::class, 'markImported'])->name('imported');
    });

    // -- Offline sync -------------------------------------------------------
    Route::post('sync/push', [SyncController::class, 'push'])->name('sync.push');

    // -- User & role management (super admin) ------------------------------
    Route::get('users/roles', [UserController::class, 'roles'])->name('users.roles');
    Route::post('users/{user}/hospitals', [UserController::class, 'syncHospitals'])->name('users.hospitals');
    Route::apiResource('users', UserController::class);

    // -- Audit trail --------------------------------------------------------
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
});

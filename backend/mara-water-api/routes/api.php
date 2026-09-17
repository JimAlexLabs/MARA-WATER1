<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\WaterTestController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PackagingRunController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\StaffLoanController;
use App\Http\Controllers\Api\SalaryTemplateController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DriverTripController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\BomController;
use App\Http\Controllers\Api\SkuController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\PettyCashController;
use App\Http\Controllers\Api\DebtorLedgerController;
use App\Http\Controllers\Api\CostingController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\WarehouseAuditController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::prefix('v1')->group(function () {
    // Authentication routes
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::get('/auth/profile', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::match(['put', 'patch'], '/auth/profile', [AuthController::class, 'updateProfile'])->middleware('auth:sanctum');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Dashboard routes
        Route::get('/dashboard/health', [DashboardController::class, 'health']);
        Route::get('/dashboard/overview', [DashboardController::class, 'overview']);

        // Global top-bar search
        Route::get('/search', [SearchController::class, 'index']);

        // System settings
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings', [SettingsController::class, 'update']);

        // Admin: backups + danger-zone reset (all admin-gated inside the controller)
        Route::prefix('admin')->group(function () {
            Route::get('/danger-zone/tables', [AdminController::class, 'tableGroups']);
            Route::get('/backups', [AdminController::class, 'listBackups']);
            Route::post('/backups', [AdminController::class, 'createBackup']);
            Route::get('/backups/{id}/download', [AdminController::class, 'downloadBackup']);
            Route::post('/backups/{id}/restore', [AdminController::class, 'restoreBackup']);
            Route::post('/reset', [AdminController::class, 'resetAllData']);
            Route::get('/reset-logs', [AdminController::class, 'listResetLogs']);
        });

        // QA routes
        Route::prefix('qa')->group(function () {
            // Fixed-segment routes (statistics, etc.) must be registered
            // before /{id} wildcards of the same length, or e.g.
            // "GET /water-tests/statistics" matches show('statistics')
            // and 404s looking for a water test literally named that.
            Route::get('/water-tests/statistics', [WaterTestController::class, 'statistics']);
            Route::get('/water-tests', [WaterTestController::class, 'index']);
            Route::post('/water-tests', [WaterTestController::class, 'store']);
            Route::get('/water-tests/{id}', [WaterTestController::class, 'show']);
            Route::put('/water-tests/{id}', [WaterTestController::class, 'update']);
            Route::delete('/water-tests/{id}', [WaterTestController::class, 'destroy']);
            Route::post('/water-tests/{id}/verify', [WaterTestController::class, 'verify']);

            Route::get('/batches/statistics', [BatchController::class, 'statistics']);
            Route::get('/batches/sku/{skuId}', [BatchController::class, 'bySku']);
            Route::get('/batches', [BatchController::class, 'index']);
            Route::post('/batches', [BatchController::class, 'store']);
            Route::get('/batches/{id}', [BatchController::class, 'show']);
            Route::put('/batches/{id}', [BatchController::class, 'update']);
            Route::delete('/batches/{id}', [BatchController::class, 'destroy']);
            Route::put('/batches/{id}/status', [BatchController::class, 'updateStatus']);

            // Warehouse & Equipment Audit (Phase 10).
            Route::get('/audit/packaging', [WarehouseAuditController::class, 'packagingWatch']);
            Route::get('/audit/stationery', [WarehouseAuditController::class, 'stationeryWatch']);
            Route::get('/audit/chemicals', [WarehouseAuditController::class, 'chemicalsWatch']);
            Route::get('/audit/ppe', [WarehouseAuditController::class, 'ppeWatch']);
            Route::get('/audit/equipment', [WarehouseAuditController::class, 'equipmentStatus']);
            Route::get('/audit/test-equipment', [WarehouseAuditController::class, 'testEquipmentStatus']);
            Route::get('/audit/critical-gaps', [WarehouseAuditController::class, 'criticalGaps']);

            Route::get('/equipment', [EquipmentController::class, 'index']);
            Route::post('/equipment', [EquipmentController::class, 'store']);
            Route::put('/equipment/{id}', [EquipmentController::class, 'update']);
            Route::delete('/equipment/{id}', [EquipmentController::class, 'destroy']);
        });

        // Production routes
        Route::prefix('production')->group(function () {
            Route::get('/packaging-runs/statistics', [PackagingRunController::class, 'statistics']);
            Route::get('/packaging-runs/batch/{batchId}', [PackagingRunController::class, 'byBatch']);
            Route::get('/packaging-runs', [PackagingRunController::class, 'index']);
            Route::post('/packaging-runs', [PackagingRunController::class, 'store']);
            Route::get('/packaging-runs/{id}', [PackagingRunController::class, 'show']);
            Route::put('/packaging-runs/{id}', [PackagingRunController::class, 'update']);
            Route::delete('/packaging-runs/{id}', [PackagingRunController::class, 'destroy']);
            Route::post('/packaging-runs/{id}/complete', [PackagingRunController::class, 'complete']);

            // SKU catalog (Phase 8) -- fixed segments before /{id}.
            Route::get('/skus', [SkuController::class, 'index']);
            Route::put('/skus/{id}', [SkuController::class, 'update']);

            // Raw materials (Phase 8) -- fixed segments before /{id}.
            Route::get('/materials', [MaterialController::class, 'index']);
            Route::post('/materials', [MaterialController::class, 'store']);
            Route::get('/materials/{id}', [MaterialController::class, 'show']);
            Route::put('/materials/{id}', [MaterialController::class, 'update']);
            Route::delete('/materials/{id}', [MaterialController::class, 'destroy']);

            // Bill of materials (Phase 8) -- what production auto-deducts.
            Route::get('/bom', [BomController::class, 'index']);
            Route::post('/bom', [BomController::class, 'store']);
            Route::put('/bom/{id}', [BomController::class, 'update']);
            Route::delete('/bom/{id}', [BomController::class, 'destroy']);
        });

        // Inventory routes
        Route::prefix('inventory')->group(function () {
            Route::get('/warehouses', [InventoryController::class, 'warehouses']);
            Route::get('/stock-items', [InventoryController::class, 'stockItems']);
            Route::get('/stock-moves', [InventoryController::class, 'stockMoves']);
            Route::post('/stock-moves', [InventoryController::class, 'createStockMove']);
            Route::get('/statistics', [InventoryController::class, 'statistics']);
            Route::get('/warehouse/{warehouseId}/stock', [InventoryController::class, 'stockByWarehouse']);
            Route::get('/low-stock', [InventoryController::class, 'lowStock']);

            // Phase 8 reports -- live views recreating the old Excel sheets.
            Route::get('/stock-card', [InventoryController::class, 'stockCard']);
            Route::get('/reconciliation', [InventoryController::class, 'reconciliation']);
            Route::get('/materials-usage', [InventoryController::class, 'materialsUsage']);
            Route::get('/refills', [InventoryController::class, 'refills']);
        });

        // Sales routes
        Route::prefix('sales')->group(function () {
            Route::get('/orders/statistics', [OrderController::class, 'statistics']);
            Route::get('/orders/customer/{customerId}', [OrderController::class, 'byCustomer']);
            Route::post('/orders/log-sale', [OrderController::class, 'logSale']);
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{id}', [OrderController::class, 'show']);
            Route::put('/orders/{id}', [OrderController::class, 'update']);
            Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
            Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);

            // Reference lists the Log-a-Sale form reads from. Fixed
            // segments before /{id}, same reason as everywhere else here.
            Route::get('/price-lists', [PriceListController::class, 'index']);
            Route::get('/price-lists/{id}/items', [PriceListController::class, 'items']);

            Route::get('/customers/statistics', [CustomerController::class, 'statistics']);
            Route::get('/customers/route/{routeId}', [CustomerController::class, 'byRoute']);
            Route::get('/customers/search', [CustomerController::class, 'search']);
            Route::post('/customers/bulk-assign-route', [CustomerController::class, 'bulkAssignRoute']);
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::get('/customers/{id}', [CustomerController::class, 'show']);
            Route::put('/customers/{id}', [CustomerController::class, 'update']);
            Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
        });

        // Finance routes
        Route::prefix('finance')->group(function () {
            Route::get('/invoices/statistics', [InvoiceController::class, 'statistics']);
            Route::get('/invoices/customer/{customerId}', [InvoiceController::class, 'byCustomer']);
            Route::get('/invoices/overdue', [InvoiceController::class, 'overdue']);
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices', [InvoiceController::class, 'store']);
            Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
            Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
            Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
            Route::post('/invoices/{id}/send', [InvoiceController::class, 'send']);
            Route::post('/invoices/{id}/mark-paid', [InvoiceController::class, 'markAsPaid']);

            // Chart of Accounts (Phase 9) -- reference table petty cash codes against.
            Route::get('/accounts', [ChartOfAccountController::class, 'index']);
            Route::post('/accounts', [ChartOfAccountController::class, 'store']);
            Route::put('/accounts/{id}', [ChartOfAccountController::class, 'update']);

            // Petty cash journal (Phase 9). Fixed segments before /{id}.
            Route::get('/petty-cash/utilization', [PettyCashController::class, 'utilization']);
            Route::get('/petty-cash', [PettyCashController::class, 'index']);
            Route::post('/petty-cash', [PettyCashController::class, 'store']);
            Route::delete('/petty-cash/{id}', [PettyCashController::class, 'destroy']);

            // Debtors ledger (Phase 9).
            Route::get('/debtors/{customerId}/ledger', [DebtorLedgerController::class, 'index']);
            Route::get('/debtors/{customerId}/open-debts', [DebtorLedgerController::class, 'openDebts']);
            Route::post('/debtors/ledger', [DebtorLedgerController::class, 'store']);
            Route::post('/debtors/debts/{debtId}/pay', [DebtorLedgerController::class, 'recordPayment']);

            // Costing & P&L (Phase 9).
            Route::get('/costing/per-bottle', [CostingController::class, 'perBottleCost']);
            Route::get('/costing/profit-loss', [CostingController::class, 'profitAndLoss']);
        });

        // Fleet routes
        Route::prefix('fleet')->group(function () {
            Route::get('/vehicles/statistics', [VehicleController::class, 'statistics']);
            Route::get('/vehicles/expiring-documents', [VehicleController::class, 'expiringDocuments']);
            Route::get('/vehicles/search', [VehicleController::class, 'search']);
            Route::get('/vehicles', [VehicleController::class, 'index']);
            Route::post('/vehicles', [VehicleController::class, 'store']);
            Route::get('/vehicles/{id}', [VehicleController::class, 'show']);
            Route::put('/vehicles/{id}', [VehicleController::class, 'update']);
            Route::delete('/vehicles/{id}', [VehicleController::class, 'destroy']);
            Route::post('/vehicles/{id}/assign-driver', [VehicleController::class, 'assignDriver']);
            Route::post('/vehicles/{id}/unassign-driver', [VehicleController::class, 'unassignDriver']);

            // Route/zone reference list drivers pick from on the trip form.
            Route::get('/routes', [DriverTripController::class, 'routes']);
            Route::post('/routes', [DriverTripController::class, 'storeRoute']);
            Route::get('/skus', [DriverTripController::class, 'skus']);

            // Driver trips (the worksheet replacement). Fixed segments first,
            // same reason as everywhere else in this file: /{id} is a
            // wildcard and would otherwise capture "statistics" etc.
            Route::get('/trips/statistics', [DriverTripController::class, 'statistics']);
            Route::get('/trips/mileage-trend', [DriverTripController::class, 'mileageTrend']);
            Route::get('/trips', [DriverTripController::class, 'index']);
            Route::post('/trips', [DriverTripController::class, 'store']);
            Route::get('/trips/{id}', [DriverTripController::class, 'show']);
            Route::put('/trips/{id}', [DriverTripController::class, 'update']);
            Route::delete('/trips/{id}', [DriverTripController::class, 'destroy']);
        });

        // HR routes
        Route::prefix('hr')->group(function () {
            Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
            Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);
            Route::get('/attendance/statistics', [AttendanceController::class, 'statistics']);
            Route::get('/attendance/user/{userId}', [AttendanceController::class, 'byUser']);
            Route::get('/attendance/today', [AttendanceController::class, 'today']);
            Route::get('/attendance', [AttendanceController::class, 'index']);
            Route::post('/attendance', [AttendanceController::class, 'store']);
            Route::get('/attendance/{id}', [AttendanceController::class, 'show']);
            Route::put('/attendance/{id}', [AttendanceController::class, 'update']);
            Route::delete('/attendance/{id}', [AttendanceController::class, 'destroy']);

            // Round 2 Phase 4: Payroll
            Route::get('/payroll/runs', [PayrollController::class, 'index']);
            Route::post('/payroll/runs', [PayrollController::class, 'store']);
            Route::get('/payroll/runs/{id}', [PayrollController::class, 'show']);
            Route::delete('/payroll/runs/{id}', [PayrollController::class, 'destroy']);
            Route::post('/payroll/runs/{id}/finalize', [PayrollController::class, 'finalize']);
            Route::get('/payroll/runs/{id}/bank-transfer-file', [PayrollController::class, 'bankTransferFile']);
            Route::put('/payroll/runs/{runId}/payslips/{payslipId}', [PayrollController::class, 'updatePayslip']);
            Route::get('/payroll/payslips/{id}', [PayrollController::class, 'payslip']);

            // Advances & Loans
            Route::get('/loans', [StaffLoanController::class, 'index']);
            Route::post('/loans', [StaffLoanController::class, 'store']);
            Route::put('/loans/{id}', [StaffLoanController::class, 'update']);
            Route::delete('/loans/{id}', [StaffLoanController::class, 'destroy']);

            // Salary/role templates
            Route::get('/salary-templates', [SalaryTemplateController::class, 'index']);
            Route::post('/salary-templates', [SalaryTemplateController::class, 'store']);
            Route::put('/salary-templates/{id}', [SalaryTemplateController::class, 'update']);
            Route::delete('/salary-templates/{id}', [SalaryTemplateController::class, 'destroy']);
        });

        // Reports routes
        Route::prefix('reports')->group(function () {
            Route::get('/dashboard', [ReportsController::class, 'dashboard']);
            Route::get('/sales', [ReportsController::class, 'salesReport']);
            Route::get('/production', [ReportsController::class, 'productionReport']);
            Route::get('/qa', [ReportsController::class, 'qaReport']);
            Route::get('/inventory', [ReportsController::class, 'inventoryReport']);
            Route::get('/attendance', [ReportsController::class, 'attendanceReport']);
            Route::get('/financial', [ReportsController::class, 'financialReport']);
        });

        // File upload routes
        Route::prefix('files')->group(function () {
            Route::post('/upload', [FileUploadController::class, 'upload']);
            Route::delete('/{id}', [FileUploadController::class, 'delete']);
            Route::get('/by-entity', [FileUploadController::class, 'getByEntity']);
        });

        // Notification routes
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::post('/mark-read', [NotificationController::class, 'markAsRead']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
            Route::delete('/{id}', [NotificationController::class, 'delete']);
            Route::post('/send', [NotificationController::class, 'send']);
            Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
        });

        // Users routes (Admin only)
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            // These fixed-segment routes must come before the /{id} wildcard,
            // or e.g. "GET /users/roles" matches show('roles') and 404s
            // looking for a user literally named "roles".
            Route::post('/bulk', [UserController::class, 'bulkStore']);
            Route::get('/statistics', [UserController::class, 'statistics']);
            Route::get('/roles', [UserController::class, 'roles']);
            Route::get('/departments', [UserController::class, 'departments']);
            Route::get('/{id}', [UserController::class, 'show']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
            Route::post('/{id}/change-password', [UserController::class, 'changePassword']);
            Route::put('/{id}/status', [UserController::class, 'updateStatus']);
        });
    });
});

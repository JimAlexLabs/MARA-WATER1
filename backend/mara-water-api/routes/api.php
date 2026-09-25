<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AnalyticsController;
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
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\MaterialBatchController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DriverTripController;
use App\Http\Controllers\Api\DiscrepancyController;
use App\Http\Controllers\Api\FuelLogController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\BomController;
use App\Http\Controllers\Api\SkuController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\PettyCashController;
use App\Http\Controllers\Api\DebtorLedgerController;
use App\Http\Controllers\Api\CostingController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\HrSecureController;
use App\Http\Controllers\Api\WarehouseAuditController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\OperationsOverviewController;

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
        // Dashboard health check -- harmless, no business data, open to
        // any authenticated tier.
        Route::get('/dashboard/health', [DashboardController::class, 'health']);

        // Round 2 Phase 11: every route below now carries a real,
        // server-side access_tier check (EnsureAccessTier / 'tier:...'
        // middleware) -- "not just hiding UI elements client-side, a
        // hidden button is not real security." Manager here means the
        // spec's "Manager / Accountant / Financier" bucket, which in
        // this app's existing role catalog is every job-title role
        // except Director and Driver (QA, RIC, BP, SMM, SO, FO, STK,
        // AUD -- see the access_tier migration).

        // The full company dashboard/analytics/search surface financial,
        // debtor, and staff detail -- Manager/Director only. Drivers get
        // their own dedicated summary instead (see /driver below);
        // Investors get their own deliberately limited one (/investor).
        Route::middleware('tier:manager,director')->group(function () {
            Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
            Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
            Route::get('/search', [SearchController::class, 'index']);
        });

        // Round 2 Phase 11: Investor's own deliberately limited, read-
        // only summary -- explicitly not line-level detail (no
        // individual salaries, debtor names, or petty cash lines).
        Route::middleware('tier:investor,director')->group(function () {
            Route::get('/investor/summary', [\App\Http\Controllers\Api\InvestorController::class, 'summary']);
            Route::get('/investor/operations-overview', [OperationsOverviewController::class, 'investorOverview']);
        });

        // Director supply-chain Operations Overview + template Excel pack.
        Route::middleware('tier:director')->prefix('operations')->group(function () {
            Route::get('/overview', [OperationsOverviewController::class, 'overview']);
            Route::get('/skus', [OperationsOverviewController::class, 'listSkus']);
            Route::post('/conversions', [OperationsOverviewController::class, 'upsertConversion']);
            Route::get('/exports/inventory-control', [OperationsOverviewController::class, 'exportInventoryControl']);
            Route::get('/exports/raw-materials', [OperationsOverviewController::class, 'exportRawMaterials']);
            Route::get('/exports/warehouse-stock', [OperationsOverviewController::class, 'exportWarehouseStock']);
            Route::get('/exports/refills', [OperationsOverviewController::class, 'exportRefills']);
            Route::get('/exports/driver-worksheet', [OperationsOverviewController::class, 'exportDriverWorksheet']);
            Route::get('/exports/sales-control', [OperationsOverviewController::class, 'exportSalesControl']);
        });

        // Round 2 Phase 11: a driver's own dashboard -- their own trip
        // history and stats only (DriverTripController enforces the
        // "own trips only" scoping itself once tier=driver).
        Route::middleware('tier:driver,manager,director')->group(function () {
            Route::get('/driver/summary', [\App\Http\Controllers\Api\DriverSummaryController::class, 'summary']);
            // Round 3 Phase 4: a driver/salesperson's own analytics.
            Route::get('/driver/analytics', [\App\Http\Controllers\Api\DriverSummaryController::class, 'analytics']);
            // Round 3 Phase 12: "Invoices"/"Debtors Ledger" scoped to
            // their own sales -- Petty Cash and Costing & P&L are
            // deliberately NOT exposed here at all (spec default: a
            // driver-tier user doesn't see either).
            Route::get('/driver/sales', [\App\Http\Controllers\Api\DriverSummaryController::class, 'mySales']);
        });

        // Round 2 Phase 11: a plain staff directory (name/role/department,
        // sensitive fields already redacted in-controller for non-
        // directors) -- Manager needs this to pick a driver/officer on the
        // Fleet trip form and staff on the HR page, not just Director.
        // Registered before the Director-only /users/{id} wildcard below --
        // Laravel matches routes in registration order, so /users/roles
        // and /users/departments have to come first or they'd match
        // /users/{id} (with id="roles") instead, under the wrong middleware.
        // Round 5B Phase 6: ALL employee record access is Director-only
        // (list/show/statistics as well as mutate). Manager keeps
        // Attendance via /hr/attendance — not raw employee records.
        Route::middleware('tier:manager,director')->prefix('users')->group(function () {
            Route::get('/roles', [UserController::class, 'roles']);
            Route::get('/departments', [UserController::class, 'departments']);
        });

        // System settings -- Director only, per the spec's own suggested
        // default (flagged in the Phase 11 report if Manager should get
        // this too).
        Route::middleware('tier:director')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::put('/settings', [SettingsController::class, 'update']);

            // Admin: backups + danger-zone reset. AdminController already
            // checks isDirector() itself (Phase 1) -- the middleware here
            // is just the same declarative gate every other route now
            // has, not a replacement for that check.
            Route::prefix('admin')->group(function () {
                Route::get('/danger-zone/tables', [AdminController::class, 'tableGroups']);
                Route::get('/backups', [AdminController::class, 'listBackups']);
                Route::post('/backups', [AdminController::class, 'createBackup']);
                Route::get('/backups/{id}/download', [AdminController::class, 'downloadBackup']);
                Route::post('/backups/{id}/restore', [AdminController::class, 'restoreBackup']);
                Route::post('/reset', [AdminController::class, 'resetAllData']);
                Route::get('/reset-logs', [AdminController::class, 'listResetLogs']);
            });

            // Users routes -- user management, role assignment, salary/
            // bank detail editing, deletion, and password resets are
            // explicitly Director-only in the spec. Round 5B Phase 6
            // also moves list/show/statistics here (Manager must not
            // reach raw employee records).
            Route::prefix('users')->group(function () {
                Route::get('/', [UserController::class, 'index']);
                Route::get('/statistics', [UserController::class, 'statistics']);
                Route::post('/', [UserController::class, 'store']);
                Route::post('/bulk', [UserController::class, 'bulkStore']);
                Route::get('/{id}', [UserController::class, 'show']);
                Route::put('/{id}', [UserController::class, 'update']);
                Route::delete('/{id}', [UserController::class, 'destroy']);
                Route::post('/{id}/change-password', [UserController::class, 'changePassword']);
                Route::put('/{id}/status', [UserController::class, 'updateStatus']);
            });
        });

        // QA routes -- Manager/Director operational access.
        Route::middleware('tier:manager,director')->prefix('qa')->group(function () {
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
            Route::get('/batches/daily', [BatchController::class, 'daily']);
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
            Route::get('/audit/receipts', [WarehouseAuditController::class, 'receiptQuality']);

            Route::get('/equipment', [EquipmentController::class, 'index']);
            Route::post('/equipment', [EquipmentController::class, 'store']);
            Route::put('/equipment/{id}', [EquipmentController::class, 'update']);
            Route::delete('/equipment/{id}', [EquipmentController::class, 'destroy']);
        });

        // Production routes -- Manager/Director operational access.
        Route::middleware('tier:manager,director')->prefix('production')->group(function () {
            // Ops brief §4: daily production rollup (date list + date drill-down).
            Route::get('/daily', [BatchController::class, 'daily']);

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
            Route::post('/skus', [SkuController::class, 'store']);
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

        // Inventory routes -- Manager/Director operational access.
        Route::middleware('tier:manager,director')->prefix('inventory')->group(function () {
            Route::get('/warehouses', [InventoryController::class, 'warehouses']);
            Route::get('/stock-items', [InventoryController::class, 'stockItems']);
            Route::get('/stock-moves', [InventoryController::class, 'stockMoves']);
            Route::post('/stock-moves', [InventoryController::class, 'createStockMove']);
            Route::get('/statistics', [InventoryController::class, 'statistics']);
            Route::get('/warehouse/{warehouseId}/stock', [InventoryController::class, 'stockByWarehouse']);
            Route::get('/low-stock', [InventoryController::class, 'lowStock']);

            // Phase 8 reports -- live views recreating the old Excel sheets.
            Route::get('/stock-card', [InventoryController::class, 'stockCard']);
            // Round 3 Phase 9: exact-format export.
            Route::get('/stock-reconciliation-export', [InventoryController::class, 'stockReconciliationExport']);
            Route::get('/reconciliation', [InventoryController::class, 'reconciliation']);
            Route::get('/materials-usage', [InventoryController::class, 'materialsUsage']);
            Route::get('/refills', [InventoryController::class, 'refills']);

            // Round 3 Phase 10: material purchase batches (traceability --
            // see MaterialBatchController docblock).
            Route::get('/material-batches', [MaterialBatchController::class, 'index']);
            Route::post('/material-batches', [MaterialBatchController::class, 'store']);
            Route::post('/material-batches/{id}/quality', [MaterialBatchController::class, 'completeQuality']);

            // Round 5B Phase 3: scheduled/on-demand reconciliation reports
            Route::get('/reconciliation-reports', [InventoryController::class, 'listReconciliationReports']);
            Route::post('/reconciliation-reports', [InventoryController::class, 'createReconciliationReport']);
            Route::get('/reconciliation-reports/{id}/download', [InventoryController::class, 'downloadReconciliationReport']);

            // Round 5B seam for Discrepancies (Claude Code): stable inventory/production figures
            Route::get('/figures', [InventoryController::class, 'figures']);
        });

        // Sales routes -- Manager/Director operational access.
        Route::middleware('tier:manager,director')->prefix('sales')->group(function () {
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
            Route::get('/prices/current', [PriceListController::class, 'currentPrice']);
            Route::get('/price-lists', [PriceListController::class, 'index']);
            Route::get('/price-lists/{id}/items', [PriceListController::class, 'items']);
            Route::get('/price-lists/{id}/export', [PriceListController::class, 'export']);
            Route::put('/price-lists/{id}/items/{skuId}', [PriceListController::class, 'upsertItem']);

            Route::get('/customers/statistics', [CustomerController::class, 'statistics']);
            Route::get('/customers/route/{routeId}', [CustomerController::class, 'byRoute']);
            Route::post('/customers/bulk-assign-route', [CustomerController::class, 'bulkAssignRoute']);
            Route::get('/customers', [CustomerController::class, 'index']);
        });

        // Round 3 Phase 3: a driver logging a trip sale needs to search
        // for/add a customer too ("typing a name/phone searches existing
        // customers first, with add new customer as a fallback") -- this
        // also fixes a real pre-existing bug where DriverPage's customer
        // picker called the Manager/Director-only /sales/customers index
        // and silently degraded to an empty list for every driver.
        // Registered before the Manager/Director-only /customers/{id}
        // wildcard below, same route-registration-order reasoning as
        // every other fixed-segment-before-wildcard fix in this file --
        // /customers/search would otherwise match /customers/{id} with
        // id="search" first.
        Route::middleware('tier:driver,manager,director')->prefix('sales')->group(function () {
            Route::get('/customers/search', [CustomerController::class, 'search']);
            Route::post('/customers', [CustomerController::class, 'store']);
        });

        Route::middleware('tier:manager,director')->prefix('sales')->group(function () {
            Route::get('/customers/{id}', [CustomerController::class, 'show']);
            Route::get('/customers/{id}/purchase-history', [CustomerController::class, 'purchaseHistory']);
            Route::put('/customers/{id}', [CustomerController::class, 'update']);
            Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
        });

        // Finance routes -- Manager/Director operational access (Petty
        // Cash, Debtors -- explicitly Manager's per the spec).
        Route::middleware('tier:manager,director')->prefix('finance')->group(function () {
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
            Route::get('/petty-cash/export', [PettyCashController::class, 'export']);
            Route::get('/petty-cash', [PettyCashController::class, 'index']);
            Route::post('/petty-cash', [PettyCashController::class, 'store']);
            Route::delete('/petty-cash/{id}', [PettyCashController::class, 'destroy']);

            // Debtors ledger (Phase 9).
            Route::get('/debtors/export', [DebtorLedgerController::class, 'export']);
            Route::get('/debtors/{customerId}/ledger', [DebtorLedgerController::class, 'index']);
            Route::get('/debtors/{customerId}/open-debts', [DebtorLedgerController::class, 'openDebts']);
            Route::post('/debtors/ledger', [DebtorLedgerController::class, 'store']);
            Route::post('/debtors/debts/{debtId}/pay', [DebtorLedgerController::class, 'recordPayment']);

            // Costing & P&L (Phase 9).
            Route::get('/costing/per-bottle', [CostingController::class, 'perBottleCost']);
            Route::get('/costing/profit-loss', [CostingController::class, 'profitAndLoss']);

            // Round 5B Phase 5: shared Manager/Director ledger summary
            Route::get('/ledger-summary', [FinanceController::class, 'ledgerSummary']);
        });

        // Ops brief §9: Director live profit — tier:director + HR unlock.
        Route::middleware(['tier:director', 'hr.unlocked'])->prefix('finance')->group(function () {
            Route::get('/profit-summary', [FinanceController::class, 'profitSummary']);
        });

        // Fleet routes -- vehicle management is Manager/Director; the
        // route/SKU reference lists and driver trips themselves are also
        // open to Driver (their own trip logging), with DriverTripController
        // itself enforcing "own trips only" once tier=driver.
        Route::middleware('tier:manager,director')->prefix('fleet')->group(function () {
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

            // Fleet-wide trip statistics/trend/export -- aggregate across
            // every driver, so Manager/Director only (a driver's own trip
            // list below is scoped to just their own trips).
            Route::get('/trips/statistics', [DriverTripController::class, 'statistics']);
            Route::get('/trips/mileage-trend', [DriverTripController::class, 'mileageTrend']);
            Route::get('/trips/export', [DriverTripController::class, 'export']);

            // Round 4 Phase 7: Mileage Logs -- fleet-wide, reachable from
            // the Fleet section (and each vehicle's own detail page, same
            // endpoint filtered by vehicle_id).
            Route::get('/mileage-logs', [DriverTripController::class, 'mileageLogs']);

            // Round 4 Phase 8: Fuel Logs -- Manager/Director only, no
            // driver-facing entry point anywhere. Fixed segments (export)
            // before the /{id} wildcard.
            Route::get('/fuel-logs/export', [FuelLogController::class, 'export']);
            Route::get('/fuel-logs', [FuelLogController::class, 'index']);
            Route::post('/fuel-logs', [FuelLogController::class, 'store']);
            Route::delete('/fuel-logs/{id}', [FuelLogController::class, 'destroy']);

            // Round 4 Phase 6: Discrepancies page -- Manager/Director
            // only, never surfaced on the Driver dashboard.
            Route::get('/discrepancies/export', [DiscrepancyController::class, 'export']);
            Route::get('/discrepancies', [DiscrepancyController::class, 'index']);
            Route::put('/discrepancies/{id}', [DiscrepancyController::class, 'updateStatus']);
        });

        Route::middleware('tier:driver,manager,director')->prefix('fleet')->group(function () {
            // Route/zone reference list drivers pick from on Sales/Customer
            // forms -- unrelated to Phase 2's free-text trip route below.
            Route::get('/routes', [DriverTripController::class, 'routes']);
            Route::post('/routes', [DriverTripController::class, 'storeRoute']);
            Route::get('/skus', [DriverTripController::class, 'skus']);
            Route::get('/vehicles-list', [DriverTripController::class, 'vehicles']);
            Route::get('/warehouses', [DriverTripController::class, 'warehouses']);
            Route::get('/authorizing-officers', [DriverTripController::class, 'authorizingOfficers']);
            // Round 3 Phase 9: KDN/KDQ/Warehouse picker for the trip form.
            Route::get('/locations', [LocationController::class, 'index']);

            // Fixed segments before the /trips/{id} wildcard group below --
            // same route-registration-order lesson as Round 2 Phase 11's
            // /users/roles bug: a wildcard registered first silently
            // swallows a same-shaped fixed segment registered after it.
            Route::get('/trips/recent-routes', [DriverTripController::class, 'recentRoutes']);

            // Driver trips (the worksheet replacement) -- index/show/update/
            // destroy/start/end/sales/sheets are scoped to "own trips only"
            // inside the controller itself when tier=driver, so a driver
            // can't see or touch another driver's trip just by knowing its
            // id. Round 3 Phase 2: the trip is now a staged workflow --
            // store() only creates stage 1+2 (pending_departure); start()/
            // addSale()/end() below are the stage 3/4/5 transitions.
            Route::get('/trips', [DriverTripController::class, 'index']);
            Route::post('/trips', [DriverTripController::class, 'store']);
            Route::get('/trips/{id}', [DriverTripController::class, 'show']);
            Route::put('/trips/{id}', [DriverTripController::class, 'update']);
            Route::delete('/trips/{id}', [DriverTripController::class, 'destroy']);
            Route::post('/trips/{id}/start', [DriverTripController::class, 'start']);
            Route::post('/trips/{id}/sales', [DriverTripController::class, 'addSale']);
            Route::post('/trips/{id}/end', [DriverTripController::class, 'end']);
            Route::get('/trips/{id}/dispatch-sheet', [DriverTripController::class, 'dispatchSheet']);
            Route::get('/trips/{id}/return-sheet', [DriverTripController::class, 'returnSheet']);
            // Round 4 Phase 4: Sales panel, downloadable as Excel.
            Route::get('/trips/{id}/sales-sheet', [DriverTripController::class, 'salesSheet']);
        });

        // Director-only unlock -- controller double-checks this too (same
        // "declarative gate, not a replacement for the real check" pattern
        // as every other Director-only route in this file).
        Route::middleware('tier:director')->prefix('fleet')->group(function () {
            Route::post('/trips/{id}/unlock', [DriverTripController::class, 'unlock']);
        });

        // HR routes -- clock-in/out is every employee's own action
        // (Driver included, per the spec's explicit "check-in/check-out"
        // grant); everything else here (viewing/managing attendance
        // records, payroll, loans, salary templates) is Manager/Director
        // "HR/Payroll entry and processing".
        Route::middleware('tier:driver,manager,director')->prefix('hr')->group(function () {
            Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
            Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);
            // Round 4 Phase 9: the roster the shared Driver/Sales/Field-
            // Work dashboard's three check-in/out controls are built
            // from -- who the Director has assigned to those roles.
            Route::get('/field-team', [AttendanceController::class, 'fieldTeam']);
        });

        Route::middleware('tier:manager,director')->prefix('hr')->group(function () {
            Route::get('/attendance/employees', [AttendanceController::class, 'attendanceEmployees']);
            Route::get('/attendance/export', [AttendanceController::class, 'export']);
            Route::get('/attendance/statistics', [AttendanceController::class, 'statistics']);
            Route::get('/attendance/user/{userId}', [AttendanceController::class, 'byUser']);
            Route::get('/attendance/today', [AttendanceController::class, 'today']);
            Route::get('/attendance', [AttendanceController::class, 'index']);
            Route::post('/attendance', [AttendanceController::class, 'store']);
            Route::get('/attendance/{id}', [AttendanceController::class, 'show']);
            Route::put('/attendance/{id}', [AttendanceController::class, 'update']);
            Route::delete('/attendance/{id}', [AttendanceController::class, 'destroy']);
        });

        // Ops brief §9: password re-auth unlock (Director's own password).
        Route::middleware('tier:director')->prefix('hr')->group(function () {
            Route::post('/secure-unlock', [HrSecureController::class, 'unlock']);
            Route::get('/secure-status', [HrSecureController::class, 'status']);
            Route::post('/secure-lock', [HrSecureController::class, 'lock']);
        });

        // Round 3 Phase 8: Payroll, Loans & Advances, and Salary Templates
        // are Director-only + HR unlock (ops brief §9 secondary gate).
        Route::middleware(['tier:director', 'hr.unlocked'])->prefix('hr')->group(function () {
            // Round 2 Phase 4: Payroll
            Route::get('/payroll/runs', [PayrollController::class, 'index']);
            Route::post('/payroll/runs', [PayrollController::class, 'store']);
            Route::get('/payroll/runs/{id}', [PayrollController::class, 'show']);
            Route::delete('/payroll/runs/{id}', [PayrollController::class, 'destroy']);
            Route::post('/payroll/runs/{id}/finalize', [PayrollController::class, 'finalize']);
            Route::get('/payroll/runs/{id}/bank-transfer-file', [PayrollController::class, 'bankTransferFile']);
            // Round 3 Phase 9: exact-format Payroll/Payslips exports.
            Route::get('/payroll/runs/{id}/payroll-export', [PayrollController::class, 'payrollSheetExport']);
            Route::get('/payroll/runs/{id}/payslips-export', [PayrollController::class, 'payslipsExport']);
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

        // Reports routes (legacy -- Round 2 Phase 9's report noted most of
        // these are still placeholder scaffolding) -- Manager/Director.
        Route::middleware('tier:manager,director')->prefix('reports')->group(function () {
            Route::get('/dashboard', [ReportsController::class, 'dashboard']);
            Route::get('/sales', [ReportsController::class, 'salesReport']);
            Route::get('/production', [ReportsController::class, 'productionReport']);
            // Round 3 Phase 9: exact-format exports.
            Route::get('/production-export', [ReportsController::class, 'productionReportExport']);
            Route::get('/daily-sales-debt-export', [ReportsController::class, 'dailySalesDebtExport']);
            Route::get('/qa', [ReportsController::class, 'qaReport']);
            Route::get('/inventory', [ReportsController::class, 'inventoryReport']);
            Route::get('/attendance', [ReportsController::class, 'attendanceReport']);
            Route::get('/financial', [ReportsController::class, 'financialReport']);
            // Round 5B Phase 7: full metric set + revenue/tax forecast
            Route::get('/analytics', [ReportsController::class, 'analytics']);
        });

        // File upload routes. Round 3 Phase 5: now actually called from the
        // frontend for the first time -- a Driver attaching an optional
        // photo to an issue report -- so this widens from
        // tier:manager,director to include driver.
        Route::middleware('tier:driver,manager,director')->prefix('files')->group(function () {
            Route::post('/upload', [FileUploadController::class, 'upload']);
            Route::delete('/{id}', [FileUploadController::class, 'delete']);
            Route::get('/by-entity', [FileUploadController::class, 'getByEntity']);
        });

        // Notification routes -- personal to whoever's logged in, so open
        // to every tier. 'send' is unused by the frontend today; kept
        // Manager/Director-only rather than open to every tier for
        // something that currently lets you notify arbitrary users.
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::post('/mark-read', [NotificationController::class, 'markAsRead']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
            Route::delete('/{id}', [NotificationController::class, 'delete']);
            Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
        });
        Route::middleware('tier:manager,director')->prefix('notifications')->group(function () {
            Route::post('/send', [NotificationController::class, 'send']);
        });

        // Round 3 Phase 5: in-app issue reporting. Driver raises + views
        // + replies to their own; Manager/Director see and reply to all;
        // only Manager/Director can move status (open/acknowledged/
        // resolved) -- reply-triggered auto-acknowledge is handled inside
        // IssueController::reply(), not here.
        Route::middleware('tier:driver,manager,director')->prefix('issues')->group(function () {
            Route::get('/', [IssueController::class, 'index']);
            Route::post('/', [IssueController::class, 'store']);
            Route::get('/{id}', [IssueController::class, 'show']);
            Route::post('/{id}/reply', [IssueController::class, 'reply']);
        });
        Route::middleware('tier:manager,director')->prefix('issues')->group(function () {
            Route::put('/{id}/status', [IssueController::class, 'updateStatus']);
        });
    });
});

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\WaterTestController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PackagingRunController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\FileUploadController;
use App\Http\Controllers\Api\NotificationController;

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

        // QA routes
        Route::prefix('qa')->group(function () {
            Route::get('/water-tests', [WaterTestController::class, 'index']);
            Route::post('/water-tests', [WaterTestController::class, 'store']);
            Route::get('/water-tests/{id}', [WaterTestController::class, 'show']);
            Route::put('/water-tests/{id}', [WaterTestController::class, 'update']);
            Route::delete('/water-tests/{id}', [WaterTestController::class, 'destroy']);
            Route::post('/water-tests/{id}/verify', [WaterTestController::class, 'verify']);
            Route::get('/water-tests/statistics', [WaterTestController::class, 'statistics']);

            Route::get('/batches', [BatchController::class, 'index']);
            Route::post('/batches', [BatchController::class, 'store']);
            Route::get('/batches/{id}', [BatchController::class, 'show']);
            Route::put('/batches/{id}', [BatchController::class, 'update']);
            Route::delete('/batches/{id}', [BatchController::class, 'destroy']);
            Route::put('/batches/{id}/status', [BatchController::class, 'updateStatus']);
            Route::get('/batches/statistics', [BatchController::class, 'statistics']);
            Route::get('/batches/sku/{skuId}', [BatchController::class, 'bySku']);
        });

        // Production routes
        Route::prefix('production')->group(function () {
            Route::get('/packaging-runs', [PackagingRunController::class, 'index']);
            Route::post('/packaging-runs', [PackagingRunController::class, 'store']);
            Route::get('/packaging-runs/{id}', [PackagingRunController::class, 'show']);
            Route::put('/packaging-runs/{id}', [PackagingRunController::class, 'update']);
            Route::delete('/packaging-runs/{id}', [PackagingRunController::class, 'destroy']);
            Route::post('/packaging-runs/{id}/complete', [PackagingRunController::class, 'complete']);
            Route::get('/packaging-runs/statistics', [PackagingRunController::class, 'statistics']);
            Route::get('/packaging-runs/batch/{batchId}', [PackagingRunController::class, 'byBatch']);
        });

        // Inventory routes
        Route::prefix('inventory')->group(function () {
            Route::get('/stock-items', [InventoryController::class, 'stockItems']);
            Route::get('/stock-moves', [InventoryController::class, 'stockMoves']);
            Route::post('/stock-moves', [InventoryController::class, 'createStockMove']);
            Route::get('/statistics', [InventoryController::class, 'statistics']);
            Route::get('/warehouse/{warehouseId}/stock', [InventoryController::class, 'stockByWarehouse']);
            Route::get('/low-stock', [InventoryController::class, 'lowStock']);
        });

        // Sales routes
        Route::prefix('sales')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{id}', [OrderController::class, 'show']);
            Route::put('/orders/{id}', [OrderController::class, 'update']);
            Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
            Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
            Route::get('/orders/statistics', [OrderController::class, 'statistics']);
            Route::get('/orders/customer/{customerId}', [OrderController::class, 'byCustomer']);

            Route::get('/customers', [CustomerController::class, 'index']);
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::get('/customers/{id}', [CustomerController::class, 'show']);
            Route::put('/customers/{id}', [CustomerController::class, 'update']);
            Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
            Route::get('/customers/statistics', [CustomerController::class, 'statistics']);
            Route::get('/customers/route/{routeId}', [CustomerController::class, 'byRoute']);
            Route::get('/customers/search', [CustomerController::class, 'search']);
            Route::post('/customers/bulk-assign-route', [CustomerController::class, 'bulkAssignRoute']);
        });

        // Finance routes
        Route::prefix('finance')->group(function () {
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices', [InvoiceController::class, 'store']);
            Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
            Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
            Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
            Route::post('/invoices/{id}/send', [InvoiceController::class, 'send']);
            Route::post('/invoices/{id}/mark-paid', [InvoiceController::class, 'markAsPaid']);
            Route::get('/invoices/statistics', [InvoiceController::class, 'statistics']);
            Route::get('/invoices/customer/{customerId}', [InvoiceController::class, 'byCustomer']);
            Route::get('/invoices/overdue', [InvoiceController::class, 'overdue']);
        });

        // Fleet routes
        Route::prefix('fleet')->group(function () {
            Route::get('/vehicles', [VehicleController::class, 'index']);
            Route::post('/vehicles', [VehicleController::class, 'store']);
            Route::get('/vehicles/{id}', [VehicleController::class, 'show']);
            Route::put('/vehicles/{id}', [VehicleController::class, 'update']);
            Route::delete('/vehicles/{id}', [VehicleController::class, 'destroy']);
            Route::post('/vehicles/{id}/assign-driver', [VehicleController::class, 'assignDriver']);
            Route::post('/vehicles/{id}/unassign-driver', [VehicleController::class, 'unassignDriver']);
            Route::get('/vehicles/statistics', [VehicleController::class, 'statistics']);
            Route::get('/vehicles/expiring-documents', [VehicleController::class, 'expiringDocuments']);
            Route::get('/vehicles/search', [VehicleController::class, 'search']);
        });

        // HR routes
        Route::prefix('hr')->group(function () {
            Route::get('/attendance', [AttendanceController::class, 'index']);
            Route::post('/attendance', [AttendanceController::class, 'store']);
            Route::get('/attendance/{id}', [AttendanceController::class, 'show']);
            Route::put('/attendance/{id}', [AttendanceController::class, 'update']);
            Route::delete('/attendance/{id}', [AttendanceController::class, 'destroy']);
            Route::post('/attendance/clock-in', [AttendanceController::class, 'clockIn']);
            Route::post('/attendance/clock-out', [AttendanceController::class, 'clockOut']);
            Route::get('/attendance/statistics', [AttendanceController::class, 'statistics']);
            Route::get('/attendance/user/{userId}', [AttendanceController::class, 'byUser']);
            Route::get('/attendance/today', [AttendanceController::class, 'today']);
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
            Route::get('/{id}', [UserController::class, 'show']);
            Route::put('/{id}', [UserController::class, 'update']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
            Route::post('/{id}/change-password', [UserController::class, 'changePassword']);
            Route::put('/{id}/status', [UserController::class, 'updateStatus']);
            Route::get('/statistics', [UserController::class, 'statistics']);
            Route::get('/roles', [UserController::class, 'roles']);
            Route::get('/departments', [UserController::class, 'departments']);
        });
    });
});

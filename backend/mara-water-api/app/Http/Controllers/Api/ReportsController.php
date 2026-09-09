<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Attendance;
use App\Models\Vehicle;
use App\Models\WaterTest;
use App\Models\Batch;
use App\Models\PackagingRun;
use App\Models\StockItem;
use App\Models\StockMove;

class ReportsController extends Controller
{
    public function dashboard(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());

            // Sales Overview
            $salesStats = Order::whereBetween('order_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_orders,
                    SUM(total_amount) as total_revenue,
                    AVG(total_amount) as avg_order_value,
                    COUNT(DISTINCT customer_id) as unique_customers
                ')->first();

            // Production Overview
            $productionStats = Batch::whereBetween('manufacture_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_batches,
                    SUM(planned_qty) as total_planned_qty
                ')->first();

            // QA Overview
            $qaStats = WaterTest::whereBetween('recorded_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_tests,
                    SUM(CASE WHEN status = "pass" THEN 1 ELSE 0 END) as passed_tests,
                    SUM(CASE WHEN status = "fail" THEN 1 ELSE 0 END) as failed_tests
                ')->first();

            // Inventory Overview
            $inventoryStats = StockItem::selectRaw('
                COUNT(*) as total_items,
                SUM(qty) as total_stock,
                COUNT(CASE WHEN qty <= 0 THEN 1 END) as out_of_stock_items
            ')->first();

            // Attendance Overview - placeholder
            $attendanceStats = (object) [
                'total_records' => 0,
                'total_hours_worked' => 0,
                'total_overtime' => 0
            ];

            // Recent Activities
            $recentOrders = Order::with(['customer'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            $recentWaterTests = collect(); // Placeholder until water_tests table exists

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ],
                    'sales_overview' => [
                        'total_orders' => $salesStats->total_orders ?? 0,
                        'total_revenue' => round($salesStats->total_revenue ?? 0, 2),
                        'avg_order_value' => round($salesStats->avg_order_value ?? 0, 2),
                        'unique_customers' => $salesStats->unique_customers ?? 0
                    ],
                    'production_overview' => [
                        'total_batches' => $productionStats->total_batches ?? 0,
                        'total_planned_qty' => $productionStats->total_planned_qty ?? 0,
                        'efficiency_percentage' => 0
                    ],
                    'qa_overview' => [
                        'total_tests' => $qaStats->total_tests ?? 0,
                        'passed_tests' => $qaStats->passed_tests ?? 0,
                        'failed_tests' => $qaStats->failed_tests ?? 0,
                        'pass_rate_percentage' => $qaStats->total_tests > 0 ? 
                            round(($qaStats->passed_tests / $qaStats->total_tests) * 100, 2) : 0
                    ],
                    'inventory_overview' => [
                        'total_items' => $inventoryStats->total_items ?? 0,
                        'total_stock' => round($inventoryStats->total_stock ?? 0, 2),
                        'out_of_stock_items' => $inventoryStats->out_of_stock_items ?? 0
                    ],
                    'attendance_overview' => [
                        'total_records' => $attendanceStats->total_records ?? 0,
                        'total_hours_worked' => round($attendanceStats->total_hours_worked ?? 0, 2),
                        'total_overtime' => round($attendanceStats->total_overtime ?? 0, 2)
                    ],
                    'recent_activities' => [
                        'recent_orders' => $recentOrders,
                        'recent_water_tests' => $recentWaterTests
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate dashboard report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function salesReport(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());
            $groupBy = $request->get('group_by', 'daily'); // daily, weekly, monthly, customer

            $query = Order::with(['customer'])
                ->whereBetween('order_date', [$dateFrom, $dateTo]);

            switch ($groupBy) {
                case 'daily':
                    $salesData = $query->selectRaw('
                        DATE(order_date) as date,
                        COUNT(*) as orders_count,
                        SUM(total_amount) as revenue,
                        AVG(total_amount) as avg_order_value
                    ')->groupBy('date')
                      ->orderBy('date')
                      ->get();
                    break;

                case 'weekly':
                    $salesData = $query->selectRaw('
                        YEARWEEK(order_date) as week,
                        COUNT(*) as orders_count,
                        SUM(total_amount) as revenue,
                        AVG(total_amount) as avg_order_value
                    ')->groupBy('week')
                      ->orderBy('week')
                      ->get();
                    break;

                case 'monthly':
                    $salesData = $query->selectRaw('
                        DATE_FORMAT(order_date, "%Y-%m") as month,
                        COUNT(*) as orders_count,
                        SUM(total_amount) as revenue,
                        AVG(total_amount) as avg_order_value
                    ')->groupBy('month')
                      ->orderBy('month')
                      ->get();
                    break;

                case 'customer':
                    $salesData = $query->selectRaw('
                        customer_id,
                        COUNT(*) as orders_count,
                        SUM(total_amount) as revenue,
                        AVG(total_amount) as avg_order_value
                    ')->groupBy('customer_id')
                      ->with('customer')
                      ->orderBy('revenue', 'desc')
                      ->get();
                    break;

                default:
                    $salesData = collect();
            }

            // Top performing customers
            $topCustomers = Order::with(['customer'])
                ->whereBetween('order_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    customer_id,
                    COUNT(*) as orders_count,
                    SUM(total_amount) as total_revenue
                ')->groupBy('customer_id')
                  ->with('customer')
                  ->orderBy('total_revenue', 'desc')
                  ->limit(10)
                  ->get();

            // Sales by status
            $salesByStatus = Order::whereBetween('order_date', [$dateFrom, $dateTo])
                ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as revenue')
                ->groupBy('status')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'group_by' => $groupBy
                    ],
                    'sales_data' => $salesData,
                    'top_customers' => $topCustomers,
                    'sales_by_status' => $salesByStatus
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate sales report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function productionReport(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());

            // Production by SKU
            $productionBySku = Batch::with(['sku'])
                ->whereBetween('manufacture_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    sku_id,
                    COUNT(*) as batches_count,
                    SUM(planned_qty) as total_planned_qty,
                    AVG(planned_qty) as avg_planned_qty
                ')->groupBy('sku_id')
                  ->with('sku')
                  ->get()
                  ->map(function ($item) {
                      $item->efficiency_percentage = 0;
                      return $item;
                  });

            // Production by status
            $productionByStatus = Batch::whereBetween('manufacture_date', [$dateFrom, $dateTo])
                ->selectRaw('status, COUNT(*) as count, SUM(planned_qty) as planned_qty')
                ->groupBy('status')
                ->get();

            // Daily production
            $dailyProduction = Batch::whereBetween('manufacture_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    DATE(manufacture_date) as date,
                    COUNT(*) as batches_count,
                    SUM(planned_qty) as planned_qty
                ')->groupBy('date')
                  ->orderBy('date')
                  ->get();

            // Packaging efficiency - placeholder
            $packagingEfficiency = collect();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ],
                    'production_by_sku' => $productionBySku,
                    'production_by_status' => $productionByStatus,
                    'daily_production' => $dailyProduction,
                    'packaging_efficiency' => $packagingEfficiency
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate production report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function qaReport(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());

            // QA tests by type
            $testsByType = WaterTest::whereBetween('recorded_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    test_type,
                    COUNT(*) as total_tests,
                    SUM(CASE WHEN status = "pass" THEN 1 ELSE 0 END) as passed_tests,
                    SUM(CASE WHEN status = "fail" THEN 1 ELSE 0 END) as failed_tests,
                    SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_tests
                ')->groupBy('test_type')
                  ->get()
                  ->map(function ($item) {
                      $item->pass_rate_percentage = $item->total_tests > 0 ? 
                          round(($item->passed_tests / $item->total_tests) * 100, 2) : 0;
                      return $item;
                  });

            // QA tests by location
            $testsByLocation = WaterTest::whereBetween('recorded_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    location_text,
                    COUNT(*) as total_tests,
                    SUM(CASE WHEN status = "pass" THEN 1 ELSE 0 END) as passed_tests,
                    SUM(CASE WHEN status = "fail" THEN 1 ELSE 0 END) as failed_tests
                ')->groupBy('location_text')
                  ->get()
                  ->map(function ($item) {
                      $item->pass_rate_percentage = $item->total_tests > 0 ? 
                          round(($item->passed_tests / $item->total_tests) * 100, 2) : 0;
                      return $item;
                  });

            // Daily QA performance
            $dailyQA = WaterTest::whereBetween('recorded_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    DATE(recorded_at) as date,
                    COUNT(*) as total_tests,
                    SUM(CASE WHEN status = "pass" THEN 1 ELSE 0 END) as passed_tests,
                    SUM(CASE WHEN status = "fail" THEN 1 ELSE 0 END) as failed_tests
                ')->groupBy('date')
                  ->orderBy('date')
                  ->get()
                  ->map(function ($item) {
                      $item->pass_rate_percentage = $item->total_tests > 0 ? 
                          round(($item->passed_tests / $item->total_tests) * 100, 2) : 0;
                      return $item;
                  });

            // Parameter analysis
            $parameterAnalysis = WaterTest::whereBetween('recorded_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    AVG(ph) as avg_ph,
                    MIN(ph) as min_ph,
                    MAX(ph) as max_ph,
                    AVG(tds) as avg_tds,
                    MIN(tds) as min_tds,
                    MAX(tds) as max_tds,
                    AVG(chlorine) as avg_chlorine,
                    MIN(chlorine) as min_chlorine,
                    MAX(chlorine) as max_chlorine
                ')->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ],
                    'tests_by_type' => $testsByType,
                    'tests_by_location' => $testsByLocation,
                    'daily_qa_performance' => $dailyQA,
                    'parameter_analysis' => $parameterAnalysis
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate QA report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function inventoryReport(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());

            // Current stock levels
            $currentStock = StockItem::with(['material', 'sku', 'warehouse'])
                ->selectRaw('
                    item_type,
                    warehouse_id,
                    COUNT(*) as items_count,
                    SUM(qty) as total_qty,
                    AVG(qty) as avg_qty
                ')->groupBy('item_type', 'warehouse_id')
                  ->with(['warehouse'])
                  ->get();

            // Stock movements
            $stockMovements = StockMove::with(['material', 'sku', 'warehouseFrom', 'warehouseTo'])
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    move_type,
                    item_type,
                    COUNT(*) as movements_count,
                    SUM(qty) as total_qty_moved,
                    AVG(unit_cost) as avg_unit_cost
                ')->groupBy('move_type', 'item_type')
                  ->get();

            // Low stock items
            $lowStockItems = StockItem::with(['material', 'sku', 'warehouse'])
                ->whereHas('material', function($q) {
                    $q->whereRaw('stock_items.qty <= materials.min_level');
                })
                ->orWhereHas('sku', function($q) {
                    $q->whereRaw('stock_items.qty <= 10'); // Assuming 10 is minimum for SKUs
                })
                ->get();

            // Stock value by warehouse
            $stockValueByWarehouse = StockItem::with(['warehouse'])
                ->selectRaw('
                    warehouse_id,
                    COUNT(*) as items_count,
                    SUM(qty) as total_qty
                ')->groupBy('warehouse_id')
                  ->with(['warehouse'])
                  ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ],
                    'current_stock' => $currentStock,
                    'stock_movements' => $stockMovements,
                    'low_stock_items' => $lowStockItems,
                    'stock_value_by_warehouse' => $stockValueByWarehouse
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate inventory report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function attendanceReport(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());

            // Attendance by department
            $attendanceByDepartment = Attendance::with(['user.department'])
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_records,
                    COUNT(DISTINCT user_id) as unique_users,
                    SUM(total_hours) as total_hours_worked,
                    SUM(overtime_hours) as total_overtime_hours,
                    AVG(total_hours) as avg_hours_per_day
                ')->groupBy('user.department_id')
                  ->with(['user.department'])
                  ->get();

            // Attendance by user
            $attendanceByUser = Attendance::with(['user'])
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->selectRaw('
                    user_id,
                    COUNT(*) as days_worked,
                    SUM(total_hours) as total_hours,
                    SUM(overtime_hours) as overtime_hours,
                    AVG(total_hours) as avg_hours_per_day,
                    COUNT(CASE WHEN clock_in_time > "08:00:00" THEN 1 END) as late_arrivals
                ')->groupBy('user_id')
                  ->with(['user'])
                  ->orderBy('total_hours', 'desc')
                  ->get();

            // Daily attendance summary
            $dailyAttendance = Attendance::whereBetween('date', [$dateFrom, $dateTo])
                ->selectRaw('
                    date,
                    COUNT(*) as total_records,
                    COUNT(DISTINCT user_id) as present_users,
                    SUM(total_hours) as total_hours_worked,
                    COUNT(CASE WHEN clock_in_time > "08:00:00" THEN 1 END) as late_arrivals
                ')->groupBy('date')
                  ->orderBy('date')
                  ->get();

            // Overtime analysis
            $overtimeAnalysis = Attendance::whereBetween('date', [$dateFrom, $dateTo])
                ->where('overtime_hours', '>', 0)
                ->selectRaw('
                    user_id,
                    COUNT(*) as overtime_days,
                    SUM(overtime_hours) as total_overtime_hours,
                    AVG(overtime_hours) as avg_overtime_hours
                ')->groupBy('user_id')
                  ->with(['user'])
                  ->orderBy('total_overtime_hours', 'desc')
                  ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ],
                    'attendance_by_department' => $attendanceByDepartment,
                    'attendance_by_user' => $attendanceByUser,
                    'daily_attendance' => $dailyAttendance,
                    'overtime_analysis' => $overtimeAnalysis
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate attendance report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function financialReport(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());

            // Revenue analysis
            $revenueAnalysis = Order::whereBetween('order_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    DATE_FORMAT(order_date, "%Y-%m") as month,
                    COUNT(*) as orders_count,
                    SUM(total_amount) as total_revenue,
                    AVG(total_amount) as avg_order_value
                ')->groupBy('month')
                  ->orderBy('month')
                  ->get();

            // Invoice analysis
            $invoiceAnalysis = Invoice::whereBetween('invoice_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    payment_status,
                    COUNT(*) as invoices_count,
                    SUM(total_amount) as total_amount,
                    AVG(total_amount) as avg_invoice_amount
                ')->groupBy('payment_status')
                  ->get();

            // Payment collection
            $paymentCollection = Invoice::whereBetween('payment_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    payment_method,
                    COUNT(*) as payments_count,
                    SUM(total_amount) as total_collected,
                    AVG(total_amount) as avg_payment_amount
                ')->groupBy('payment_method')
                  ->get();

            // Outstanding invoices
            $outstandingInvoices = Invoice::where('payment_status', 'pending')
                ->selectRaw('
                    COUNT(*) as invoices_count,
                    SUM(total_amount) as total_outstanding,
                    AVG(DATEDIFF(NOW(), due_date)) as avg_days_overdue
                ')->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ],
                    'revenue_analysis' => $revenueAnalysis,
                    'invoice_analysis' => $invoiceAnalysis,
                    'payment_collection' => $paymentCollection,
                    'outstanding_invoices' => $outstandingInvoices
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate financial report',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

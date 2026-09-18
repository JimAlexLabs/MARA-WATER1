<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Attendance;
use App\Models\Vehicle;
use App\Models\WaterTest;
use App\Models\Batch;
use App\Models\PackagingRun;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Services\SalesRevenueService;

class ReportsController extends Controller
{
    public function dashboard(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->endOfMonth()->toDateString());

            // Sales Overview. Round 2 Phase 12 finding: total_orders/
            // total_revenue/avg_order_value used to count every order
            // regardless of payment_method (a draft order from the dead
            // store()/updateStatus() workflow, Phase 8's report, was
            // counted as real revenue) and never counted driver trip
            // sales at all (Round 2 Phase 6-8) -- both fixed via the
            // shared SalesRevenueService, same fix Analytics got in
            // Phase 9.
            $salesStats = Order::completedSale()->whereBetween('order_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_orders,
                    SUM(total_amount) as total_revenue,
                    AVG(total_amount) as avg_order_value,
                    COUNT(DISTINCT customer_id) as unique_customers
                ')->first();
            $tripRevenue = (new SalesRevenueService())->tripRevenueBetween($dateFrom, $dateTo);
            $salesStats->total_revenue = (float) ($salesStats->total_revenue ?? 0) + $tripRevenue;

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

            // Round 2 Phase 12 finding: every query below used to count
            // every order regardless of payment_method (a draft order
            // from the dead store()/updateStatus() workflow, Phase 8's
            // report, was counted as real revenue).
            $query = Order::completedSale()->with(['customer'])
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
            $topCustomers = Order::completedSale()->with(['customer'])
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

            // Sales by status. Deliberately NOT filtered by completedSale()
            // -- this is meant to show the order pipeline including drafts,
            // not a revenue figure.
            $salesByStatus = Order::whereBetween('order_date', [$dateFrom, $dateTo])
                ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as revenue')
                ->groupBy('status')
                ->get();

            // By outlet/branch and by product (brand+size) -- replaces the
            // 31-tabs-per-month-per-outlet Excel pattern with a live report,
            // with dispatched vs returned vs net sold per the spec.
            $byOutlet = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereBetween('orders.order_date', [$dateFrom, $dateTo])
                ->whereNull('orders.deleted_at')
                ->whereNull('order_items.deleted_at')
                ->whereNotNull('orders.payment_method')
                ->selectRaw('
                    orders.warehouse_id,
                    SUM(order_items.qty) as qty_dispatched,
                    SUM(order_items.qty_returned) as qty_returned,
                    SUM(order_items.qty - order_items.qty_returned) as qty_net_sold,
                    SUM((order_items.qty - order_items.qty_returned) * order_items.unit_price) as revenue
                ')
                ->groupBy('orders.warehouse_id')
                ->get();

            $warehouses = \App\Models\Warehouse::whereIn('id', $byOutlet->pluck('warehouse_id')->filter())
                ->get()->keyBy('id');

            $byOutlet = $byOutlet->map(function ($row) use ($warehouses) {
                return [
                    'warehouse_id' => $row->warehouse_id,
                    'warehouse' => $row->warehouse_id ? ($warehouses->get($row->warehouse_id)) : null,
                    'qty_dispatched' => (int) $row->qty_dispatched,
                    'qty_returned' => (int) $row->qty_returned,
                    'qty_net_sold' => (int) $row->qty_net_sold,
                    'revenue' => round((float) $row->revenue, 2),
                ];
            });

            // By product: combines order_items with driver_trip_items --
            // a driver's sale of a product is exactly as real as an
            // outlet's (Round 2 Phase 6-8), the same combine-both-sources
            // fix Analytics (Phase 9) and Costing & P&L / Refills
            // (Phase 12) needed.
            $orderBySku = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereBetween('orders.order_date', [$dateFrom, $dateTo])
                ->whereNull('orders.deleted_at')
                ->whereNull('order_items.deleted_at')
                ->whereNotNull('orders.payment_method')
                ->selectRaw('
                    order_items.sku_id,
                    SUM(order_items.qty) as qty_dispatched,
                    SUM(order_items.qty_returned) as qty_returned,
                    SUM(order_items.qty - order_items.qty_returned) as qty_net_sold,
                    SUM((order_items.qty - order_items.qty_returned) * order_items.unit_price) as revenue
                ')
                ->groupBy('order_items.sku_id')
                ->get()->keyBy('sku_id');

            $tripBySku = \App\Models\DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
                ->whereNull('driver_trips.deleted_at')
                ->whereBetween('driver_trips.trip_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    driver_trip_items.sku_id,
                    SUM(driver_trip_items.qty_carried) as qty_dispatched,
                    SUM(driver_trip_items.qty_returned) as qty_returned,
                    SUM(driver_trip_items.qty_sold) as qty_net_sold,
                    SUM(driver_trip_items.qty_sold * driver_trip_items.unit_price) as revenue
                ')
                ->groupBy('driver_trip_items.sku_id')
                ->get()->keyBy('sku_id');

            $skuIds = collect($orderBySku->keys())->merge($tripBySku->keys())->unique()->values();
            $skusById = \App\Models\Sku::whereIn('id', $skuIds)->get()->keyBy('id');

            $bySku = $skuIds->map(function ($skuId) use ($orderBySku, $tripBySku, $skusById) {
                $o = $orderBySku->get($skuId);
                $t = $tripBySku->get($skuId);
                return [
                    'sku_id' => $skuId,
                    'sku' => $skusById->get($skuId),
                    'qty_dispatched' => (int) (($o->qty_dispatched ?? 0) + ($t->qty_dispatched ?? 0)),
                    'qty_returned' => (int) (($o->qty_returned ?? 0) + ($t->qty_returned ?? 0)),
                    'qty_net_sold' => (int) (($o->qty_net_sold ?? 0) + ($t->qty_net_sold ?? 0)),
                    'revenue' => round((float) ($o->revenue ?? 0) + (float) ($t->revenue ?? 0), 2),
                ];
            })->sortByDesc('revenue')->values();

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
                    'sales_by_status' => $salesByStatus,
                    'by_outlet' => $byOutlet,
                    'by_sku' => $bySku,
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

    /**
     * Round 3 Phase 9: "[MONTH] [YEAR] PRODUCTION DATA" exact-format
     * .xlsx -- see ProductionReportExportService for the layout.
     */
    public function productionReportExport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return (new \App\Services\ProductionReportExportService())->generate((int) $request->year, (int) $request->month);
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

            // Low stock items -- each item against its own reorder point
            // (materials.min_level, skus.reorder_threshold), not one flat
            // number for everything.
            $lowStockItems = StockItem::with(['material', 'sku', 'warehouse'])
                ->whereHas('material', function($q) {
                    $q->whereRaw('stock_items.qty <= materials.min_level');
                })
                ->orWhereHas('sku', function($q) {
                    $q->whereRaw('stock_items.qty <= COALESCE(skus.reorder_threshold, 10)');
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

    /**
     * Round 3 Phase 9: "DAILY SALES AND DEBT REPORT [MONTH]" -- exact-
     * format .xlsx, see DailySalesDebtReportService for the layout.
     */
    public function dailySalesDebtExport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return (new \App\Services\DailySalesDebtReportService())->generate((int) $request->year, (int) $request->month);
    }
}

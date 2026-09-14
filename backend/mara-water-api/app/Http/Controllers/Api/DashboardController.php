<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Batch;
use App\Models\PackagingRun;
use App\Models\WaterTest;
use App\Models\StockItem;
use App\Models\Vehicle;
use App\Models\Attendance;
use App\Models\Debt;
use App\Models\FuelLog;
use App\Models\Invoice;

class DashboardController extends Controller
{
    /**
     * Health check endpoint
     */
    public function health(Request $request)
    {
        try {
            // Check database connection
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Exception $e) {
            $dbStatus = 'disconnected';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'healthy',
                'timestamp' => now()->toISOString(),
                'version' => '1.0.0',
                'database' => $dbStatus,
                'user' => Auth::user() ? [
                    'id' => Auth::user()->id,
                    'name' => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                    'role' => Auth::user()->role->name ?? 'Unknown',
                ] : null,
            ]
        ]);
    }

    // Below this stock quantity an item counts as "low stock" -- matches
    // InventoryController::lowStock()'s own default threshold.
    private const LOW_STOCK_THRESHOLD = 10;

    // A vehicle document counts as "expiring soon" inside this window.
    private const DOC_EXPIRY_WINDOW_DAYS = 14;

    /**
     * Dashboard overview with real key metrics, 7-day trends, alerts and
     * recent activity. Every number here is a live query -- nothing hardcoded.
     * Where the underlying workflow doesn't populate data yet (debtor
     * ledger, fuel logging -- both are later phases), the query is still
     * real; it will just read 0 / empty until that data exists.
     */
    public function overview(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->toDateString();
            $monthStart = now()->startOfMonth()->toDateString();
            $weekStart = now()->subDays(6)->startOfDay();

            // --- Today ---
            $todaysOrders = Order::whereDate('order_date', $today);
            $todaysSales = (clone $todaysOrders)->sum('total_amount');
            $todaysOrdersCount = (clone $todaysOrders)->count();

            $todaysProductionLiters = PackagingRun::join('skus', 'skus.id', '=', 'packaging_runs.sku_id')
                ->whereDate('packaging_runs.run_end', $today)
                ->sum(DB::raw('packaging_runs.good_qty * skus.size_liters'));

            $qaToday = WaterTest::whereDate('recorded_at', $today);
            $qaTodayTotal = (clone $qaToday)->count();
            $qaTodayPassed = (clone $qaToday)->where('status', 'pass')->count();

            $staffPresentToday = Attendance::whereDate('date', $today)
                ->distinct('user_id')->count('user_id');

            $lowStockCount = StockItem::where('qty', '<=', self::LOW_STOCK_THRESHOLD)->count();
            $debtorBalanceOutstanding = (float) Debt::sum('balance');
            $pendingOrders = Order::whereNotIn('status', ['delivered', 'cancelled', 'partially_returned'])->count();
            $activeVehicles = Vehicle::where('active', true)->count();
            $totalVehicles = Vehicle::count();

            // --- This month ---
            $monthOrders = Order::where('order_date', '>=', $monthStart);
            $monthRevenue = (clone $monthOrders)->sum('total_amount');
            $monthOrdersCount = (clone $monthOrders)->count();
            $monthBatches = Batch::where('manufacture_date', '>=', $monthStart)->count();
            $monthWaterTests = WaterTest::where('recorded_at', '>=', $monthStart)->count();

            // --- 7-day trends ---
            $salesTrend = Order::where('order_date', '>=', $weekStart->toDateString())
                ->selectRaw('order_date as date, SUM(total_amount) as amount, COUNT(*) as orders')
                ->groupBy('order_date')->orderBy('order_date')->get();

            $productionTrend = PackagingRun::join('skus', 'skus.id', '=', 'packaging_runs.sku_id')
                ->whereNotNull('packaging_runs.run_end')
                ->where('packaging_runs.run_end', '>=', $weekStart)
                ->selectRaw('DATE(packaging_runs.run_end) as date, SUM(packaging_runs.good_qty * skus.size_liters) as liters')
                ->groupBy('date')->orderBy('date')->get();

            $fuelTrend = FuelLog::where('date', '>=', $weekStart->toDateString())
                ->selectRaw('date, SUM(cost) as cost, SUM(liters) as liters')
                ->groupBy('date')->orderBy('date')->get();

            // --- Alerts, ranked by what actually blocks the business ---
            $alerts = [];

            $lowStockItems = StockItem::with(['material', 'sku'])
                ->where('qty', '<=', self::LOW_STOCK_THRESHOLD)
                ->orderBy('qty')
                ->limit(5)->get();
            foreach ($lowStockItems as $item) {
                $name = $item->sku->name ?? $item->material->name ?? 'Item';
                $alerts[] = [
                    'type' => 'low_stock',
                    'severity' => 'high',
                    'message' => "Low stock: {$name} ({$item->qty} left)",
                    'route' => '/inventory?q=' . urlencode($name),
                ];
            }

            $expiringVehicles = Vehicle::where(function ($q) {
                $q->where('insurance_expiry', '<=', now()->addDays(self::DOC_EXPIRY_WINDOW_DAYS))
                  ->orWhere('inspection_expiry', '<=', now()->addDays(self::DOC_EXPIRY_WINDOW_DAYS));
            })->limit(5)->get();
            foreach ($expiringVehicles as $v) {
                $alerts[] = [
                    'type' => 'vehicle_document',
                    'severity' => 'medium',
                    'message' => "{$v->reg_no}: insurance/inspection document expiring soon",
                    'route' => '/fleet?q=' . urlencode($v->reg_no),
                ];
            }

            $overdueDebts = Debt::with('customer')->where('days_overdue', '>', 0)
                ->orderByDesc('days_overdue')->limit(5)->get();
            foreach ($overdueDebts as $d) {
                $customerName = $d->customer->name ?? 'Customer';
                $alerts[] = [
                    'type' => 'overdue_debt',
                    'severity' => 'high',
                    'message' => "{$customerName} overdue {$d->days_overdue} days: KES " . number_format((float) $d->balance, 2),
                    'route' => '/finance?q=' . urlencode($customerName),
                ];
            }

            $failedTests = WaterTest::where('status', 'fail')->whereDate('recorded_at', $today)->count();
            if ($failedTests > 0) {
                $alerts[] = [
                    'type' => 'qa_failure',
                    'severity' => 'high',
                    'message' => "{$failedTests} water test(s) failed today",
                    'route' => '/qa',
                ];
            }

            $severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            usort($alerts, fn ($a, $b) => $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']]);

            // --- Recent activity across modules, newest first ---
            $recentOrders = Order::with('customer')->latest()->limit(5)->get()->map(fn ($o) => [
                'type' => 'order',
                'message' => "Order {$o->order_no} · " . ($o->customer->name ?? 'Customer') . " · " . ucfirst($o->status),
                'at' => $o->created_at,
                'route' => '/sales?tab=orders&q=' . urlencode($o->order_no),
            ]);
            $recentBatches = Batch::with('sku')->latest()->limit(5)->get()->map(fn ($b) => [
                'type' => 'batch',
                'message' => "Batch {$b->code} · " . ($b->sku->name ?? 'SKU') . " · " . ucfirst($b->status),
                'at' => $b->created_at,
                'route' => '/qa?q=' . urlencode($b->code),
            ]);
            $recentTests = WaterTest::latest('recorded_at')->limit(5)->get()->map(fn ($t) => [
                'type' => 'water_test',
                'message' => ucfirst($t->test_type ?? 'Water test') . " · " . strtoupper($t->status),
                'at' => $t->recorded_at,
                'route' => '/qa',
            ]);
            $recentInvoices = Invoice::with('customer')->latest()->limit(5)->get()->map(fn ($i) => [
                'type' => 'invoice',
                'message' => "Invoice {$i->invoice_no} · " . ($i->customer->name ?? 'Customer') . " · " . ucfirst($i->status),
                'at' => $i->created_at,
                'route' => '/finance?q=' . urlencode($i->invoice_no),
            ]);

            $recentActivity = collect()
                ->concat($recentOrders)->concat($recentBatches)
                ->concat($recentTests)->concat($recentInvoices)
                ->sortByDesc('at')->values()->take(8);

            return response()->json([
                'success' => true,
                'data' => [
                    'today' => [
                        'production_liters' => round((float) $todaysProductionLiters, 1),
                        'sales_amount' => round((float) $todaysSales, 2),
                        'orders' => $todaysOrdersCount,
                        'qa_tests' => $qaTodayTotal,
                        'qa_pass_rate' => $qaTodayTotal > 0 ? round($qaTodayPassed / $qaTodayTotal * 100, 1) : null,
                        'staff_present' => $staffPresentToday,
                    ],
                    'this_month' => [
                        'revenue' => round((float) $monthRevenue, 2),
                        'orders' => $monthOrdersCount,
                        'batches' => $monthBatches,
                        'water_tests' => $monthWaterTests,
                    ],
                    'snapshot' => [
                        'low_stock_items' => $lowStockCount,
                        'debtor_balance_outstanding' => round($debtorBalanceOutstanding, 2),
                        'pending_orders' => $pendingOrders,
                        'active_vehicles' => $activeVehicles,
                        'total_vehicles' => $totalVehicles,
                    ],
                    'trends' => [
                        'sales_7d' => $salesTrend,
                        'production_liters_7d' => $productionTrend,
                        'fuel_cost_7d' => $fuelTrend,
                    ],
                    'alerts' => array_slice($alerts, 0, 8),
                    'recent_activity' => $recentActivity,
                    'user_info' => [
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'role' => $user->role->name ?? 'Unknown',
                        'department' => $user->department->name ?? 'Unknown',
                        'last_login' => $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : 'Never',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard overview',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

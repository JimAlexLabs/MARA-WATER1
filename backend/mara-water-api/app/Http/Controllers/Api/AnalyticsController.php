<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\DriverTrip;
use App\Models\DriverTripItem;
use App\Models\DriverTripSale;
use App\Models\PackagingRun;
use App\Models\Debt;
use App\Models\PayrollRun;
use App\Models\Route as RouteModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Round 2 Phase 9: a dedicated analytics view, separate from the Phase 2
 * dashboard redesign -- everything here is a live query against current
 * data (no cached/precomputed snapshots), so a short-interval refresh on
 * the frontend genuinely reflects what's happening right now.
 *
 * Two sales entry points feed real revenue in this app now (Round 2
 * Phase 6/7/8): OrderController::logSale() (outlet/direct sales -> orders
 * + order_items) and DriverTripController::store() (driver trips ->
 * driver_trip_sales + driver_trip_items). Every section below that
 * touches "sales" combines both -- the existing report endpoints
 * (ReportsController::salesReport) only ever looked at orders, which
 * would silently under-report revenue now that driver trips carry real
 * sales too.
 *
 * Fleet activity and inventory levels are deliberately NOT duplicated
 * here -- DriverTripController::statistics()/mileageTrend() and
 * InventoryController::statistics()/lowStock() already cover those
 * correctly (tested live in Phases 7/8); the frontend calls them
 * directly alongside this endpoint instead of a second, drifting copy
 * of the same logic living here too.
 */
class AnalyticsController extends Controller
{
    private function dateGroupExpr(string $column, string $groupBy): string
    {
        return match ($groupBy) {
            'weekly' => "YEARWEEK({$column})",
            'monthly' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "DATE({$column})",
        };
    }

    public function overview(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(29)->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());
            $groupBy = in_array($request->get('group_by'), ['weekly', 'monthly']) ? $request->get('group_by') : 'daily';

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'group_by' => $groupBy],
                    'sales' => $this->salesSection($dateFrom, $dateTo, $groupBy),
                    'money_flow' => $this->moneyFlowSection($dateFrom, $dateTo),
                    'production_vs_sales' => $this->productionVsSalesSection($dateFrom, $dateTo, $groupBy),
                    'debtors' => $this->debtorsSection(),
                    'payroll' => $this->payrollSection(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate analytics overview',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sales trend (combined), by brand, by outlet, and by route.
     */
    private function salesSection(string $dateFrom, string $dateTo, string $groupBy): array
    {
        $orderExpr = $this->dateGroupExpr('order_date', $groupBy);
        $tripExpr = $this->dateGroupExpr('driver_trips.trip_date', $groupBy);

        $ordersTrend = Order::completedSale()->whereBetween('order_date', [$dateFrom, $dateTo])
            ->whereNull('deleted_at')
            ->selectRaw("{$orderExpr} as period, SUM(total_amount) as revenue")
            ->groupBy('period')->pluck('revenue', 'period');

        $tripsTrend = DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')
            ->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$dateFrom, $dateTo])
            ->selectRaw("{$tripExpr} as period, SUM(driver_trip_sales.amount) as revenue")
            ->groupBy('period')->pluck('revenue', 'period');

        $periods = collect($ordersTrend->keys())->merge($tripsTrend->keys())->unique()->sort()->values();
        $trend = $periods->map(fn ($p) => [
            'period' => $p,
            'orders_revenue' => round((float) ($ordersTrend[$p] ?? 0), 2),
            'driver_trip_revenue' => round((float) ($tripsTrend[$p] ?? 0), 2),
            'total_revenue' => round((float) ($ordersTrend[$p] ?? 0) + (float) ($tripsTrend[$p] ?? 0), 2),
        ])->values();

        // By brand: combines net-sold order items with driver-trip-item
        // qty_sold*unit_price. Deliberately basis 'qty_sold', not implied
        // carried-returned -- see DriverTrip::getReconciliationAttribute()
        // for why those two can legitimately differ.
        $orderByBrand = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('skus', 'skus.id', '=', 'order_items.sku_id')
            ->whereNull('orders.deleted_at')->whereNull('order_items.deleted_at')
            ->whereNotNull('orders.payment_method')
            ->whereBetween('orders.order_date', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(skus.brand, "Mara Water") as brand,
                SUM(order_items.qty - order_items.qty_returned) as qty_sold,
                SUM((order_items.qty - order_items.qty_returned) * order_items.unit_price) as revenue')
            ->groupBy('brand')->get()->keyBy('brand');

        $tripByBrand = DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
            ->join('skus', 'skus.id', '=', 'driver_trip_items.sku_id')
            ->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(skus.brand, "Mara Water") as brand,
                SUM(driver_trip_items.qty_sold) as qty_sold,
                SUM(driver_trip_items.qty_sold * driver_trip_items.unit_price) as revenue')
            ->groupBy('brand')->get()->keyBy('brand');

        $brands = collect($orderByBrand->keys())->merge($tripByBrand->keys())->unique()->values();
        $byBrand = $brands->map(function ($brand) use ($orderByBrand, $tripByBrand) {
            $o = $orderByBrand->get($brand);
            $t = $tripByBrand->get($brand);
            return [
                'brand' => $brand,
                'qty_sold' => (int) (($o->qty_sold ?? 0) + ($t->qty_sold ?? 0)),
                'revenue' => round((float) ($o->revenue ?? 0) + (float) ($t->revenue ?? 0), 2),
            ];
        })->sortByDesc('revenue')->values();

        // By outlet (orders only -- driver trips don't sell "at" a
        // warehouse, they draw stock from one; see by_route below instead).
        $byOutlet = Order::join('warehouses', 'warehouses.id', '=', 'orders.warehouse_id')
            ->whereNull('orders.deleted_at')->whereNotNull('orders.payment_method')
            ->whereBetween('orders.order_date', [$dateFrom, $dateTo])
            ->selectRaw('warehouses.id, warehouses.name, SUM(orders.total_amount) as revenue, COUNT(*) as orders')
            ->groupBy('warehouses.id', 'warehouses.name')
            ->orderByDesc('revenue')
            ->get();

        // By route (driver trips only).
        $byRouteRaw = DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$dateFrom, $dateTo])
            ->selectRaw('driver_trips.route_id, SUM(driver_trip_sales.amount) as revenue')
            ->groupBy('driver_trips.route_id')
            ->orderByDesc('revenue')
            ->get();
        $routeNames = RouteModel::whereIn('id', $byRouteRaw->pluck('route_id')->filter())->pluck('name', 'id');
        $byRoute = $byRouteRaw->map(fn ($r) => [
            'route_id' => $r->route_id,
            'route' => $r->route_id ? ($routeNames[$r->route_id] ?? 'Unknown route') : 'No route set',
            'revenue' => round((float) $r->revenue, 2),
        ]);

        return [
            'trend' => $trend,
            'total_revenue' => round((float) $trend->sum('total_revenue'), 2),
            'by_brand' => $byBrand,
            'by_outlet' => $byOutlet,
            'by_route' => $byRoute,
        ];
    }

    /**
     * Money sold by payment method (combined orders + driver trip sales),
     * cash/M-Pesa actually collected (immediate -- there's no separate
     * receivables step for those), and outstanding debt.
     */
    private function moneyFlowSection(string $dateFrom, string $dateTo): array
    {
        // Draft orders (Order::store(), never reaches the frontend today --
        // see Phase 8's report) have no payment_method yet; excluded, since
        // nothing has actually been "sold" until logSale() commits one.
        $orderPayments = Order::whereBetween('order_date', [$dateFrom, $dateTo])
            ->whereNull('deleted_at')->whereNotNull('payment_method')
            ->selectRaw('payment_method, SUM(total_amount) as amount')
            ->groupBy('payment_method')->pluck('amount', 'payment_method');

        $tripPayments = DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$dateFrom, $dateTo])
            ->selectRaw('payment_method, SUM(amount) as amount')
            ->groupBy('payment_method')->pluck('amount', 'payment_method');

        // Orders call it 'credit', driver trips call the exact same thing
        // 'debt' -- same money, different label at each entry point.
        $cash = (float) ($orderPayments['cash'] ?? 0) + (float) ($tripPayments['cash'] ?? 0);
        $mpesa = (float) ($orderPayments['mpesa'] ?? 0) + (float) ($tripPayments['mpesa'] ?? 0);
        $debt = (float) ($orderPayments['credit'] ?? 0) + (float) ($tripPayments['debt'] ?? 0);

        // Outstanding debt is a live current balance, not a date-ranged
        // figure -- same as DashboardController::overview()'s snapshot.
        $totalOutstanding = (float) Debt::sum('balance');
        $overdue = Debt::where('balance', '>', 0)
            ->whereRaw('DATEDIFF(CURDATE(), COALESCE(expected_repayment_date, (SELECT due_date FROM invoices WHERE invoices.id = debts.invoice_id))) > 0')
            ->selectRaw('COUNT(*) as cnt, SUM(balance) as total')
            ->first();

        return [
            'sold' => [
                'cash' => round($cash, 2),
                'mpesa' => round($mpesa, 2),
                'debt' => round($debt, 2),
                'total' => round($cash + $mpesa + $debt, 2),
            ],
            'collected' => [
                // Cash and M-Pesa sales are received the moment they're
                // logged -- there's no separate "collection" step for
                // either, unlike debt.
                'cash' => round($cash, 2),
                'mpesa' => round($mpesa, 2),
                'total' => round($cash + $mpesa, 2),
            ],
            'outstanding_debt' => [
                'total_balance' => round($totalOutstanding, 2),
                'overdue_count' => (int) ($overdue->cnt ?? 0),
                'overdue_total' => round((float) ($overdue->total ?? 0), 2),
            ],
        ];
    }

    /**
     * Production vs. sales -- are we producing enough to meet demand, or
     * building up unsold stock. Sold quantity uses the same combined
     * (orders + driver trips) basis as the sales section above.
     */
    private function productionVsSalesSection(string $dateFrom, string $dateTo, string $groupBy): array
    {
        $prodExpr = $this->dateGroupExpr('packaging_runs.run_end', $groupBy);
        $orderExpr = $this->dateGroupExpr('orders.order_date', $groupBy);
        $tripExpr = $this->dateGroupExpr('driver_trips.trip_date', $groupBy);

        // run_end is a timestamp (production finishes at a specific
        // moment), not a plain date like order_date/trip_date -- comparing
        // it against a bare date_to string would exclude everything from
        // today (midnight cutoff), so widen the upper bound to end-of-day.
        $dateToEnd = \Carbon\Carbon::parse($dateTo)->endOfDay();

        $productionTrend = PackagingRun::join('skus', 'skus.id', '=', 'packaging_runs.sku_id')
            ->whereNotNull('packaging_runs.run_end')
            ->whereBetween('packaging_runs.run_end', [$dateFrom, $dateToEnd])
            ->selectRaw("{$prodExpr} as period, SUM(packaging_runs.good_qty * skus.size_liters) as liters")
            ->groupBy('period')->pluck('liters', 'period');

        $orderSalesTrend = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('skus', 'skus.id', '=', 'order_items.sku_id')
            ->whereNull('orders.deleted_at')->whereNull('order_items.deleted_at')
            ->whereNotNull('orders.payment_method')
            ->whereBetween('orders.order_date', [$dateFrom, $dateTo])
            ->selectRaw("{$orderExpr} as period, SUM((order_items.qty - order_items.qty_returned) * skus.size_liters) as liters")
            ->groupBy('period')->pluck('liters', 'period');

        $tripSalesTrend = DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
            ->join('skus', 'skus.id', '=', 'driver_trip_items.sku_id')
            ->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$dateFrom, $dateTo])
            ->selectRaw("{$tripExpr} as period, SUM(driver_trip_items.qty_sold * skus.size_liters) as liters")
            ->groupBy('period')->pluck('liters', 'period');

        $periods = collect($productionTrend->keys())->merge($orderSalesTrend->keys())->merge($tripSalesTrend->keys())
            ->unique()->sort()->values();
        $trend = $periods->map(fn ($p) => [
            'period' => $p,
            'production_liters' => round((float) ($productionTrend[$p] ?? 0), 1),
            'sales_liters' => round((float) ($orderSalesTrend[$p] ?? 0) + (float) ($tripSalesTrend[$p] ?? 0), 1),
        ])->values();

        // By brand: produced vs sold quantity (units, not liters -- easier
        // to read per product than a liters figure across mixed sizes).
        $producedByBrand = PackagingRun::join('skus', 'skus.id', '=', 'packaging_runs.sku_id')
            ->whereNotNull('packaging_runs.run_end')
            ->whereBetween('packaging_runs.run_end', [$dateFrom, $dateToEnd])
            ->selectRaw('COALESCE(skus.brand, "Mara Water") as brand, SUM(packaging_runs.good_qty) as qty')
            ->groupBy('brand')->pluck('qty', 'brand');

        $soldByBrandOrders = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('skus', 'skus.id', '=', 'order_items.sku_id')
            ->whereNull('orders.deleted_at')->whereNull('order_items.deleted_at')
            ->whereNotNull('orders.payment_method')
            ->whereBetween('orders.order_date', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(skus.brand, "Mara Water") as brand, SUM(order_items.qty - order_items.qty_returned) as qty')
            ->groupBy('brand')->pluck('qty', 'brand');

        $soldByBrandTrips = DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
            ->join('skus', 'skus.id', '=', 'driver_trip_items.sku_id')
            ->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$dateFrom, $dateTo])
            ->selectRaw('COALESCE(skus.brand, "Mara Water") as brand, SUM(driver_trip_items.qty_sold) as qty')
            ->groupBy('brand')->pluck('qty', 'brand');

        $brands = collect($producedByBrand->keys())->merge($soldByBrandOrders->keys())->merge($soldByBrandTrips->keys())
            ->unique()->values();
        $byBrand = $brands->map(function ($brand) use ($producedByBrand, $soldByBrandOrders, $soldByBrandTrips) {
            $produced = (int) ($producedByBrand[$brand] ?? 0);
            $sold = (int) (($soldByBrandOrders[$brand] ?? 0) + ($soldByBrandTrips[$brand] ?? 0));
            return [
                'brand' => $brand,
                'produced_qty' => $produced,
                'sold_qty' => $sold,
                'variance' => $produced - $sold,
            ];
        })->values();

        return [
            'trend' => $trend,
            'total_production_liters' => round((float) $trend->sum('production_liters'), 1),
            'total_sales_liters' => round((float) $trend->sum('sales_liters'), 1),
            'by_brand' => $byBrand,
        ];
    }

    /**
     * Debtor balances and overdue totals -- a live snapshot, not
     * date-ranged (a debtor balance doesn't belong to a period).
     */
    private function debtorsSection(): array
    {
        $totalBalance = (float) Debt::sum('balance');
        $totalDebts = Debt::where('balance', '>', 0)->count();

        $overdue = Debt::where('balance', '>', 0)
            ->whereRaw('DATEDIFF(CURDATE(), COALESCE(expected_repayment_date, (SELECT due_date FROM invoices WHERE invoices.id = debts.invoice_id))) > 0')
            ->selectRaw('COUNT(*) as cnt, SUM(balance) as total')
            ->first();

        $topDebtors = Debt::with('customer')
            ->where('balance', '>', 0)
            ->select('customer_id', DB::raw('SUM(balance) as balance'))
            ->groupBy('customer_id')
            ->orderByDesc('balance')
            ->limit(10)
            ->get()
            ->map(fn ($d) => [
                'customer_id' => $d->customer_id,
                'customer' => $d->customer->name ?? 'Customer',
                'balance' => round((float) $d->balance, 2),
            ]);

        return [
            'total_balance' => round($totalBalance, 2),
            'total_debts' => $totalDebts,
            'overdue_count' => (int) ($overdue->cnt ?? 0),
            'overdue_total' => round((float) ($overdue->total ?? 0), 2),
            'top_debtors' => $topDebtors,
        ];
    }

    /**
     * Payroll cost trend -- last 12 calendar months, independent of the
     * main date filter (a meaningful trend needs several months of
     * history, and payroll is monthly by nature, not daily/weekly like
     * the sales-oriented sections above). Finalized runs only -- a draft
     * in progress isn't a committed cost yet.
     */
    private function payrollSection(): array
    {
        $since = now()->subMonths(11)->startOfMonth()->toDateString();

        $runs = PayrollRun::where('status', 'finalized')
            ->where('month', '>=', $since)
            ->with('payslips')
            ->orderBy('month')
            ->get();

        $trend = $runs->map(function ($run) {
            $grossPay = (float) $run->payslips->sum('gross_pay');
            $employerNssf = (float) $run->payslips->sum('company_nssf');
            return [
                'month' => $run->month->format('Y-m'),
                'gross_pay' => round($grossPay, 2),
                'employer_nssf' => round($employerNssf, 2),
                'total_cost' => round($grossPay + $employerNssf, 2),
                'net_pay' => round((float) $run->payslips->sum('net_salary'), 2),
                'employees' => $run->payslips->count(),
            ];
        })->values();

        return [
            'trend' => $trend,
            'latest_month_cost' => $trend->last()['total_cost'] ?? 0,
        ];
    }
}

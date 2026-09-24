<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\DriverTripSale;
use App\Models\PackagingRun;
use App\Models\Debt;
use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Round 2 Phase 11 / Round 5A Phase 2: the Investor role's own dashboard
 * -- "a daily performance summary (revenue, production, sales trend,
 * high-level financial health) -- explicitly not line-level detail like
 * individual salaries, individual debtor names, or petty cash line
 * items. Build this as a distinct, deliberately limited dashboard, not
 * the full app with buttons hidden."
 *
 * Every figure here is a total or a trend -- nothing keyed by customer,
 * employee, or petty-cash line. There is deliberately no "drill down"
 * endpoint an Investor-tier request could reach to get from a total to
 * the records behind it; the 'tier' middleware on every operational
 * route (sales, finance, hr, etc.) blocks that outright, not just this
 * summary being the only thing linked from the UI.
 *
 * Round 5A Phase 2 fills this out properly: Sales/Production/Finance
 * trends (not just today/this-month totals), plus a "debt and
 * stock-depletion alerts" summary -- counts and totals only, e.g. "3 new
 * debts this week, KES 42,000" and "5 items low on stock", never a
 * debtor-by-name list or a per-item drill-down (that's what Finance and
 * Inventory are for, and this role can't reach either).
 */
class InvestorController extends Controller
{
    public function summary(Request $request)
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $trendStart = now()->subDays(29)->toDateString();

        $ordersRevenue = fn ($from, $to) => Order::completedSale()
            ->whereBetween('order_date', [$from, $to])->whereNull('deleted_at')
            ->sum('total_amount');

        $tripRevenue = fn ($from, $to) => DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$from, $to])
            ->sum('driver_trip_sales.amount');

        $productionLiters = fn ($from, $to) => (float) PackagingRun::join('skus', 'skus.id', '=', 'packaging_runs.sku_id')
            ->whereNotNull('packaging_runs.run_end')
            ->whereBetween('packaging_runs.run_end', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->sum(DB::raw('packaging_runs.good_qty * skus.size_liters'));

        $todayRevenue = round((float) $ordersRevenue($today, $today) + (float) $tripRevenue($today, $today), 2);
        $monthRevenue = round((float) $ordersRevenue($monthStart, $today) + (float) $tripRevenue($monthStart, $today), 2);

        // Sales trend -- one total per day, no by-customer/by-brand
        // breakdown; that level of detail is exactly what's excluded here.
        $ordersTrend = Order::completedSale()->whereBetween('order_date', [$trendStart, $today])
            ->whereNull('deleted_at')
            ->selectRaw('order_date as date, SUM(total_amount) as revenue')
            ->groupBy('date')->pluck('revenue', 'date');

        $tripsTrend = DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$trendStart, $today])
            ->selectRaw('driver_trips.trip_date as date, SUM(driver_trip_sales.amount) as revenue')
            ->groupBy('date')->pluck('revenue', 'date');

        $dates = collect($ordersTrend->keys())->merge($tripsTrend->keys())->unique()->sort()->values();
        $salesTrend = $dates->map(fn ($d) => [
            'date' => $d,
            'revenue' => round((float) ($ordersTrend[$d] ?? 0) + (float) ($tripsTrend[$d] ?? 0), 2),
        ])->values();

        // Round 5A Phase 2: Production analytics needs its own trend too
        // -- previously this dashboard only had today/this-month totals,
        // no way to see the shape of production over time the way sales
        // already had.
        $productionTrend = PackagingRun::join('skus', 'skus.id', '=', 'packaging_runs.sku_id')
            ->whereNotNull('packaging_runs.run_end')
            ->whereBetween('packaging_runs.run_end', ["{$trendStart} 00:00:00", "{$today} 23:59:59"])
            ->selectRaw('DATE(packaging_runs.run_end) as date, SUM(packaging_runs.good_qty * skus.size_liters) as liters')
            ->groupBy('date')->orderBy('date')
            ->get()->map(fn ($r) => ['date' => $r->date, 'liters' => round((float) $r->liters, 1)])->values();

        // High-level financial health -- totals only. Cash/M-Pesa are
        // collected the moment they're sold (no separate receivables
        // step); debt is one outstanding total, never a debtor list.
        $orderPayments = Order::completedSale()->whereBetween('order_date', [$monthStart, $today])
            ->whereNull('deleted_at')
            ->selectRaw('payment_method, SUM(total_amount) as amount')
            ->groupBy('payment_method')->pluck('amount', 'payment_method');
        $tripPayments = DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$monthStart, $today])
            ->selectRaw('payment_method, SUM(amount) as amount')
            ->groupBy('payment_method')->pluck('amount', 'payment_method');
        $cashCollectedMonth = round((float) ($orderPayments['cash'] ?? 0) + (float) ($tripPayments['cash'] ?? 0), 2);
        $mpesaCollectedMonth = round((float) ($orderPayments['mpesa'] ?? 0) + (float) ($tripPayments['mpesa'] ?? 0), 2);

        // Round 5A Phase 2: "Debt and stock-depletion alerts -- a simple
        // summary of new debt entries and low-stock/depletion warnings,
        // so the investor stays aware of financial and operational risk
        // without needing operational access." Counts and totals only --
        // never a debtor-by-name list (that's Finance) or a per-item
        // reorder workflow (that's Inventory), neither of which this
        // tier can reach.
        $newDebtWindowStart = now()->subDays(7);
        $newDebts = Debt::where('created_at', '>=', $newDebtWindowStart);
        $overdueDebts = Debt::where('balance', '>', 0)
            ->where(function ($q) {
                $q->where('expected_repayment_date', '<', now()->toDateString())
                  ->orWhereHas('invoice', fn ($iq) => $iq->where('due_date', '<', now()->toDateString()));
            });

        // Same per-item reorder point as Inventory/Manager-dashboard's own
        // low-stock query (materials.min_level, skus.reorder_threshold,
        // falling back to a flat 10 for SKUs without one set).
        $lowStockQuery = StockItem::whereNull('deleted_at')->where(function ($q) {
            $q->whereHas('material', function ($m) {
                $m->whereColumn('stock_items.qty', '<=', 'materials.min_level');
            })->orWhereHas('sku', function ($s) {
                $s->whereRaw('stock_items.qty <= COALESCE(skus.reorder_threshold, 10)');
            });
        });
        $lowStockItems = (clone $lowStockQuery)->with(['material', 'sku'])->orderBy('qty')->limit(5)->get()
            ->map(fn ($i) => $i->sku->name ?? $i->material->name ?? 'Item')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'revenue' => $todayRevenue,
                    'production_liters' => round($productionLiters($today, $today), 1),
                ],
                'this_month' => [
                    'revenue' => $monthRevenue,
                    'production_liters' => round($productionLiters($monthStart, $today), 1),
                    'cash_collected' => $cashCollectedMonth,
                    'mpesa_collected' => $mpesaCollectedMonth,
                ],
                'sales_trend' => $salesTrend,
                'production_trend' => $productionTrend,
                'financial_health' => [
                    'cash_and_mpesa_collected_month' => round($cashCollectedMonth + $mpesaCollectedMonth, 2),
                    'outstanding_debt_total' => round((float) Debt::sum('balance'), 2),
                ],
                'alerts' => [
                    'new_debt' => [
                        'window_days' => 7,
                        'count' => (clone $newDebts)->count(),
                        'total' => round((float) (clone $newDebts)->sum('principal'), 2),
                    ],
                    'overdue_debt' => [
                        'count' => (clone $overdueDebts)->count(),
                        'total' => round((float) (clone $overdueDebts)->sum('balance'), 2),
                    ],
                    'low_stock' => [
                        'count' => (clone $lowStockQuery)->count(),
                        'items' => $lowStockItems,
                    ],
                ],
            ],
        ]);
    }
}

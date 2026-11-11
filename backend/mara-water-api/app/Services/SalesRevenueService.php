<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\DriverTripSale;
use App\Models\DriverTripItem;
use Illuminate\Support\Collection;

/**
 * Round 2 Phase 12 (re-verifying the Excel mapping now that it's live).
 * Two revenue sources are real now: OrderController::logSale() (outlet/
 * direct sales) and DriverTripController::store() (driver trips, Round 2
 * Phase 6-8). Every place in the app that reports "sales"/"revenue" has
 * to combine both, or it silently under-reports the moment a driver
 * trip carries a real sale -- this is the same bug AnalyticsController
 * was fixed for in Phase 9; this service is what the other reports that
 * needed the same fix during Phase 12 (Dashboard, Costing & P&L, Refills
 * tracking) share, instead of a third and fourth drifting copy of it.
 *
 * Also centralizes Order::completedSale() (payment_method IS NOT NULL --
 * a draft order from the dead store()/updateStatus() workflow, Phase 8's
 * report, was found during Phase 12 actively inflating the live
 * Dashboard/Costing/Reports figures with a phantom KES 500 sale that was
 * never actually completed).
 */
class SalesRevenueService
{
    public function ordersRevenueBetween(string $from, string $to): float
    {
        return (float) $this->bottledOrderLines($from, $to)
            ->sum(\Illuminate\Support\Facades\DB::raw('(order_items.qty - COALESCE(order_items.qty_returned, 0)) * order_items.unit_price'));
    }

    public function tripRevenueBetween(string $from, string $to): float
    {
        return (float) $this->bottledTripLines($from, $to)
            ->sum(\Illuminate\Support\Facades\DB::raw('driver_trip_sale_items.qty_bales * driver_trip_sale_items.unit_price'));
    }

    public function combinedRevenueBetween(string $from, string $to): float
    {
        return round($this->ordersRevenueBetween($from, $to) + $this->tripRevenueBetween($from, $to), 2);
    }

    /**
     * One row per calendar date in range: orders_revenue, driver_trip_revenue,
     * total_revenue, orders_count (order count only -- a driver trip isn't
     * "an order").
     */
    public function dailyTrend(string $from, string $to): Collection
    {
        $orders = $this->bottledOrderLines($from, $to)
            ->selectRaw('orders.order_date as date, SUM((order_items.qty - COALESCE(order_items.qty_returned, 0)) * order_items.unit_price) as revenue, COUNT(DISTINCT orders.id) as orders_count')
            ->groupBy('date')->get()->keyBy('date');

        $trips = $this->bottledTripLines($from, $to)
            ->selectRaw('driver_trips.trip_date as date, SUM(driver_trip_sale_items.qty_bales * driver_trip_sale_items.unit_price) as revenue')
            ->groupBy('date')->get()->keyBy('date');

        $dates = collect($orders->keys())->merge($trips->keys())->unique()->sort()->values();

        return $dates->map(function ($date) use ($orders, $trips) {
            $ordersRevenue = (float) ($orders[$date]->revenue ?? 0);
            $tripRevenue = (float) ($trips[$date]->revenue ?? 0);
            return [
                'date' => $date,
                'orders_count' => (int) ($orders[$date]->orders_count ?? 0),
                'orders_revenue' => round($ordersRevenue, 2),
                'driver_trip_revenue' => round($tripRevenue, 2),
                'total_revenue' => round($ordersRevenue + $tripRevenue, 2),
            ];
        })->values();
    }

    /**
     * Net qty sold and revenue per SKU, combining order_items (net of
     * returns) and driver_trip_items (qty_sold, the reported-sold basis --
     * see DriverTrip::getReconciliationAttribute() for why that's the
     * right basis, not implied carried-returned).
     */
    public function netQtyAndRevenueBySku(string $from, string $to): Collection
    {
        $orderRows = $this->bottledOrderLines($from, $to)
            ->selectRaw('order_items.sku_id, SUM(order_items.qty - order_items.qty_returned) as net_qty, SUM((order_items.qty - order_items.qty_returned) * order_items.unit_price) as revenue')
            ->groupBy('order_items.sku_id')->get()->keyBy('sku_id');

        $tripRows = $this->bottledTripLines($from, $to)
            ->selectRaw('driver_trip_sale_items.sku_id, SUM(driver_trip_sale_items.qty_bales) as net_qty, SUM(driver_trip_sale_items.qty_bales * driver_trip_sale_items.unit_price) as revenue')
            ->groupBy('driver_trip_sale_items.sku_id')->get()->keyBy('sku_id');

        $skuIds = collect($orderRows->keys())->merge($tripRows->keys())->unique()->values();

        return $skuIds->map(function ($skuId) use ($orderRows, $tripRows) {
            $o = $orderRows->get($skuId);
            $t = $tripRows->get($skuId);
            return [
                'sku_id' => $skuId,
                'net_qty_sold' => (int) (($o->net_qty ?? 0) + ($t->net_qty ?? 0)),
                'revenue' => round((float) ($o->revenue ?? 0) + (float) ($t->revenue ?? 0), 2),
            ];
        })->values();
    }

    /**
     * Round 3 Phase 3: a customer's running totals -- "computed from
     * their sales history, not manually entered" per the spec. Combines
     * Order (counter/outlet) and DriverTripSale (on-the-road) the same
     * way every other report here does.
     */
    public function customerStats(string $customerId): array
    {
        $orders = Order::completedSale()->whereNull('deleted_at')->where('customer_id', $customerId)
            ->selectRaw('orders.payment_method, orders.order_date, '.self::bottledOrderRevenueSql('orders').' as bottled_amount')
            ->get()
            ->filter(fn ($o) => (float) $o->bottled_amount > 0)
            ->values();
        $tripQuery = DriverTripSale::whereNull('deleted_at')->where('customer_id', $customerId);
        if (\Illuminate\Support\Facades\Schema::hasColumn('driver_trip_sales', 'source_order_id')) {
            $tripQuery->whereNull('source_order_id');
        }
        $trips = $tripQuery
            ->selectRaw('driver_trip_sales.payment_method, driver_trip_sales.created_at, '.self::bottledTripRevenueSql('driver_trip_sales').' as bottled_amount')
            ->get()
            ->filter(fn ($s) => (float) $s->bottled_amount > 0)
            ->values();

        $lifetimePurchases = round((float) $orders->sum('bottled_amount') + (float) $trips->sum('bottled_amount'), 2);

        $lastOrderDate = $orders->max('order_date');
        $lastTripDate = optional($trips->sortByDesc('created_at')->first())->created_at;
        $lastPurchaseDate = collect([$lastOrderDate, $lastTripDate])->filter()->map(fn ($d) => (string) $d)->sort()->last();

        // Preferred payment method: whichever appears most often across
        // both sources, counted by number of sales (not by KES value) --
        // "how do they usually pay", not "which method moved more money".
        $methodCounts = $orders->pluck('payment_method')
            ->merge($trips->pluck('payment_method'))
            ->filter()
            ->countBy();
        $preferredMethod = $methodCounts->sortDesc()->keys()->first();

        return [
            'lifetime_purchases' => $lifetimePurchases,
            'last_purchase_date' => $lastPurchaseDate,
            'preferred_payment_method' => $preferredMethod,
            'payment_method_breakdown' => $methodCounts,
            'order_count' => $orders->count(),
            'trip_sale_count' => $trips->count(),
        ];
    }

    /**
     * Round 3 Phase 3: one chronological list combining every Order and
     * DriverTripSale for this customer -- the Customer Detail page's
     * purchase history table + purchases-over-time chart read this.
     */
    public function customerPurchaseHistory(string $customerId): Collection
    {
        $orders = Order::completedSale()->whereNull('deleted_at')->where('customer_id', $customerId)
            ->selectRaw('orders.*, '.self::bottledOrderRevenueSql('orders').' as bottled_amount')
            ->orderByDesc('order_date')
            ->get()
            ->filter(fn ($o) => (float) $o->bottled_amount > 0)
            ->map(fn ($o) => [
                'source' => 'order',
                'id' => $o->id,
                'date' => (string) $o->order_date,
                'reference' => $o->order_no,
                'amount' => (float) $o->bottled_amount,
                'payment_method' => $o->payment_method,
            ]);

        $tripQuery = DriverTripSale::with('trip')->whereNull('deleted_at')->where('customer_id', $customerId);
        if (\Illuminate\Support\Facades\Schema::hasColumn('driver_trip_sales', 'source_order_id')) {
            $tripQuery->whereNull('source_order_id');
        }
        $trips = $tripQuery
            ->selectRaw('driver_trip_sales.*, '.self::bottledTripRevenueSql('driver_trip_sales').' as bottled_amount')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($s) => (float) $s->bottled_amount > 0)
            ->map(fn ($s) => [
                'source' => 'driver_trip',
                'id' => $s->id,
                'date' => optional($s->trip)->trip_date ? (string) $s->trip->trip_date : (string) $s->created_at->toDateString(),
                'reference' => $s->physical_receipt_no ?: ('Trip ' . substr($s->driver_trip_id, 0, 8)),
                'amount' => (float) $s->bottled_amount,
                'payment_method' => $s->payment_method,
            ]);

        // ->map() above turned each row into a plain array, but the
        // collection returned by ->get()->map() is still typed as
        // Eloquent\Collection -- its merge() is overridden to dedupe by
        // primary key (calls ->getKey() on each item), which crashes on
        // a plain array. ->toBase() drops back to the base Collection
        // (plain array merge) before combining the two sources.
        return $orders->toBase()->merge($trips->toBase())->sortByDesc('date')->values();
    }

    /**
     * Sales figures are 0.5L, 1L and 1.5L only. Refill lines stay on the
     * admin refill tab and are not part of revenue.
     */
    public static function bottledOrderRevenueSql(string $orderAlias = 'orders'): string
    {
        return '(SELECT COALESCE(SUM((oi.qty - COALESCE(oi.qty_returned, 0)) * oi.unit_price), 0)
            FROM order_items oi
            INNER JOIN skus sk ON sk.id = oi.sku_id
            WHERE oi.order_id = '.$orderAlias.'.id
              AND oi.deleted_at IS NULL
              AND '.self::bottledSkuSql('sk').')';
    }

    public static function bottledTripRevenueSql(string $saleAlias = 'driver_trip_sales'): string
    {
        return '(SELECT COALESCE(SUM(ti.qty_bales * ti.unit_price), 0)
            FROM driver_trip_sale_items ti
            INNER JOIN skus sk ON sk.id = ti.sku_id
            WHERE ti.driver_trip_sale_id = '.$saleAlias.'.id
              AND '.self::bottledSkuSql('sk').')';
    }

    public static function bottledSkuSql(string $alias): string
    {
        return "(
            ({$alias}.size_liters BETWEEN 0.45 AND 0.55
              OR {$alias}.size_liters BETWEEN 0.95 AND 1.05
              OR {$alias}.size_liters BETWEEN 1.45 AND 1.55)
            AND ({$alias}.brand IS NULL OR {$alias}.brand NOT LIKE '%Refill%')
            AND {$alias}.code NOT LIKE 'REFILL%'
        )";
    }

    private function bottledOrderLines(string $from, string $to)
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('skus', 'skus.id', '=', 'order_items.sku_id')
            ->whereNull('orders.deleted_at')
            ->whereNull('order_items.deleted_at')
            ->whereNotNull('orders.payment_method')
            ->where(function ($q) {
                $q->whereNull('orders.payment_status')->orWhere('orders.payment_status', 'paid');
            })
            ->whereBetween('orders.order_date', [$from, $to])
            ->whereRaw(self::bottledSkuSql('skus'));
    }

    private function bottledTripLines(string $from, string $to)
    {
        $query = \App\Models\DriverTripSaleItem::query()
            ->join('driver_trip_sales', 'driver_trip_sales.id', '=', 'driver_trip_sale_items.driver_trip_sale_id')
            ->join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->join('skus', 'skus.id', '=', 'driver_trip_sale_items.sku_id')
            ->whereNull('driver_trip_sales.deleted_at')
            ->whereNull('driver_trips.deleted_at')
            ->where(function ($q) {
                $q->whereNull('driver_trip_sales.payment_status')->orWhere('driver_trip_sales.payment_status', 'paid');
            })
            ->whereBetween('driver_trips.trip_date', [$from, $to])
            ->whereRaw(self::bottledSkuSql('skus'));

        if (\Illuminate\Support\Facades\Schema::hasColumn('driver_trip_sales', 'source_order_id')) {
            $query->whereNull('driver_trip_sales.source_order_id');
        }

        return $query;
    }
}

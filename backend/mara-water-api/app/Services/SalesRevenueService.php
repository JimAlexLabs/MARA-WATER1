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
        return (float) Order::completedSale()
            ->whereBetween('order_date', [$from, $to])
            ->whereNull('deleted_at')
            ->sum('total_amount');
    }

    public function tripRevenueBetween(string $from, string $to): float
    {
        return (float) DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$from, $to])
            ->sum('driver_trip_sales.amount');
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
        $orders = Order::completedSale()->whereBetween('order_date', [$from, $to])
            ->whereNull('deleted_at')
            ->selectRaw('order_date as date, SUM(total_amount) as revenue, COUNT(*) as orders_count')
            ->groupBy('date')->get()->keyBy('date');

        $trips = DriverTripSale::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->whereNull('driver_trip_sales.deleted_at')->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$from, $to])
            ->selectRaw('driver_trips.trip_date as date, SUM(driver_trip_sales.amount) as revenue')
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
        $orderRows = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')->whereNull('order_items.deleted_at')
            ->whereNotNull('orders.payment_method')
            ->whereBetween('orders.order_date', [$from, $to])
            ->selectRaw('order_items.sku_id, SUM(order_items.qty - order_items.qty_returned) as net_qty, SUM((order_items.qty - order_items.qty_returned) * order_items.unit_price) as revenue')
            ->groupBy('order_items.sku_id')->get()->keyBy('sku_id');

        $tripRows = DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
            ->whereNull('driver_trips.deleted_at')
            ->whereBetween('driver_trips.trip_date', [$from, $to])
            ->selectRaw('driver_trip_items.sku_id, SUM(driver_trip_items.qty_sold) as net_qty, SUM(driver_trip_items.qty_sold * driver_trip_items.unit_price) as revenue')
            ->groupBy('driver_trip_items.sku_id')->get()->keyBy('sku_id');

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
            ->get(['total_amount', 'payment_method', 'order_date']);
        $trips = DriverTripSale::whereNull('deleted_at')->where('customer_id', $customerId)
            ->get(['amount', 'payment_method', 'created_at']);

        $lifetimePurchases = round((float) $orders->sum('total_amount') + (float) $trips->sum('amount'), 2);

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
            ->orderByDesc('order_date')
            ->get()
            ->map(fn ($o) => [
                'source' => 'order',
                'id' => $o->id,
                'date' => (string) $o->order_date,
                'reference' => $o->order_no,
                'amount' => (float) $o->total_amount,
                'payment_method' => $o->payment_method,
            ]);

        $trips = DriverTripSale::with('trip')->whereNull('deleted_at')->where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => [
                'source' => 'driver_trip',
                'id' => $s->id,
                'date' => optional($s->trip)->trip_date ? (string) $s->trip->trip_date : (string) $s->created_at->toDateString(),
                'reference' => $s->physical_receipt_no ?: ('Trip ' . substr($s->driver_trip_id, 0, 8)),
                'amount' => (float) $s->amount,
                'payment_method' => $s->payment_method,
            ]);

        return $orders->merge($trips)->sortByDesc('date')->values();
    }
}

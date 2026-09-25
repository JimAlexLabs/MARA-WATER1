<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Discrepancy;
use App\Models\DriverAssignment;
use App\Models\DriverTrip;
use App\Models\DriverTripItem;
use App\Models\DriverTripSale;
use App\Models\DriverTripSaleItem;
use App\Models\FuelLog;
use App\Models\Issue;
use App\Models\VehicleRepair;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Round 2 Phase 11: a driver's own landing dashboard -- "their own trip
 * history ... no visibility into other drivers, finance, or HR." Every
 * query here is scoped to the logged-in driver; there's no id param to
 * widen it to anyone else's data.
 */
class DriverSummaryController extends Controller
{
    public function summary(Request $request)
    {
        $userId = Auth::id();
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $todayAttendance = Attendance::where('user_id', $userId)->whereDate('date', $today)->first();

        $monthTrips = DriverTrip::with(['vehicle', 'sales'])
            ->where('driver_id', $userId)
            ->where('trip_date', '>=', $monthStart)
            ->get();

        $recentTrips = DriverTrip::with(['vehicle', 'sales'])
            ->where('driver_id', $userId)
            ->orderByDesc('trip_date')->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'trip_date' => $t->trip_date,
                'vehicle' => $t->vehicle->reg_no ?? null,
                'route' => $t->route,
                'status' => $t->status,
                'has_discrepancy' => $t->has_discrepancy,
                'km_covered' => $t->km_covered,
                'total_collected' => $t->total_collected,
                'reconciliation' => [
                    'matches' => $t->reconciliation['matches'],
                    'stock_matches' => $t->reconciliation['stock_matches'],
                ],
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'clocked_in' => (bool) ($todayAttendance?->clock_in_time),
                    'clocked_out' => (bool) ($todayAttendance?->clock_out_time),
                    'clock_in_time' => $todayAttendance?->clock_in_time?->format('H:i'),
                    'clock_out_time' => $todayAttendance?->clock_out_time?->format('H:i'),
                ],
                'this_month' => [
                    'trips' => $monthTrips->count(),
                    'km_covered' => (int) $monthTrips->sum('km_covered'),
                    'total_collected' => round((float) $monthTrips->sum('total_collected'), 2),
                ],
                'recent_trips' => $recentTrips,
            ],
        ]);
    }

    /**
     * Round 3 Phase 4: a driver/salesperson's own analytics dashboard --
     * everything here is scoped to the logged-in driver, same "no
     * visibility into other drivers' numbers" rule as summary() above.
     */
    public function analytics(Request $request)
    {
        $userId = Auth::id();
        $weekStart = now()->startOfWeek()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        // Sales this week/month, KES and bales -- bales here is net
        // dispatched (carried - returned) from the driver's own trip
        // items, not the optional per-sale line items, so the number is
        // always complete rather than depending on whether a sale was
        // itemized (see driver_trip_sale_items' own docblock).
        $salesFor = function (string $since) use ($userId) {
            $kes = (float) DriverTripSale::whereNull('driver_trip_sales.deleted_at')
                ->join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
                ->where('driver_trips.driver_id', $userId)
                ->where('driver_trips.trip_date', '>=', $since)
                ->sum('driver_trip_sales.amount');

            $bales = (int) DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
                ->where('driver_trips.driver_id', $userId)
                ->where('driver_trips.trip_date', '>=', $since)
                // Cast to SIGNED first -- both columns are `int
                // unsigned`, and a row where returned exceeds carried
                // (bad pre-Round-4 historical data, since Returned used
                // to be manually typed) would otherwise wrap the
                // subtraction to a huge positive number and overflow
                // SUM() into a BIGINT UNSIGNED range error.
                ->selectRaw('SUM(CAST(driver_trip_items.qty_carried_bales AS SIGNED) - CAST(driver_trip_items.qty_returned_bales AS SIGNED)) as net_bales')
                ->value('net_bales');

            return ['kes' => round($kes, 2), 'bales' => $bales];
        };

        // Quantity sold by brand/size -- their currently active trip (if
        // any) and their all-time cumulative, both from qty_sold (the
        // reported-sold basis, same one the reconciliation accessor uses
        // -- see DriverTripItem model docblock).
        // Round 4 Phase 1: bales, not bottles -- and Phase 2's "only
        // display items with quantity greater than zero" (moot for
        // current_trip now that dispatch quantities are required|min:1,
        // but the >0 filter stays as a defensive floor).
        $activeTrip = DriverTrip::where('driver_id', $userId)->where('status', 'in_transit')->first();
        $currentTripQty = $activeTrip
            ? DriverTripItem::with('sku')->where('driver_trip_id', $activeTrip->id)
                ->where('qty_carried_bales', '>', 0)
                ->get()->map(fn ($i) => ['sku_id' => $i->sku_id, 'name' => $i->sku->name ?? 'Item', 'brand' => $i->sku->brand ?? null, 'qty_carried_bales' => $i->qty_carried_bales])
                ->values()
            : [];

        // Round 4 Phase 0/2: "Sold" is the live cumulative total from the
        // Sales entity's own line items now, not the trip item's own
        // qty_sold column (which pre-Round-4 history denominated in
        // bottles -- mixing that with bale-based figures here would
        // silently corrupt the total for any driver with older trips).
        $cumulativeQty = DriverTripSaleItem::join('driver_trip_sales', 'driver_trip_sales.id', '=', 'driver_trip_sale_items.driver_trip_sale_id')
            ->join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->join('skus', 'skus.id', '=', 'driver_trip_sale_items.sku_id')
            ->where('driver_trips.driver_id', $userId)
            ->whereNull('driver_trip_sales.deleted_at')
            ->selectRaw('driver_trip_sale_items.sku_id, skus.name, skus.brand, SUM(driver_trip_sale_items.qty_bales) as qty_sold')
            ->groupBy('driver_trip_sale_items.sku_id', 'skus.name', 'skus.brand')
            ->having('qty_sold', '>', 0)
            ->orderByDesc('qty_sold')
            ->get();

        // New customers this driver has registered -- a running,
        // all-time tally (Customer::created_by is set on every quick-add
        // from the Log a Sale flow -- Phase 3).
        $newCustomersCount = Customer::whereNull('deleted_at')->where('created_by', $userId)->count();

        // Debt sales outstanding/overdue -- only debts that originated
        // from THIS driver's own trip sales (debt_id on driver_trip_sales
        // for their trips), not the company-wide debtor book.
        $theirDebtIds = DriverTripSale::whereNull('driver_trip_sales.deleted_at')
            ->join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->where('driver_trips.driver_id', $userId)
            ->whereNotNull('driver_trip_sales.debt_id')
            ->pluck('driver_trip_sales.debt_id');

        $theirDebts = Debt::whereIn('id', $theirDebtIds)->where('balance', '>', 0)->get();
        $overdueDebts = $theirDebts->filter(fn ($d) => $d->days_overdue > 0);

        return response()->json([
            'success' => true,
            'data' => [
                'sales' => [
                    'this_week' => $salesFor($weekStart),
                    'this_month' => $salesFor($monthStart),
                ],
                'new_customers' => ['count' => $newCustomersCount],
                'qty_by_brand_size' => [
                    'current_trip' => $currentTripQty,
                    'cumulative' => $cumulativeQty,
                ],
                'debt' => [
                    'outstanding' => round((float) $theirDebts->sum('balance'), 2),
                    'overdue' => round((float) $overdueDebts->sum('balance'), 2),
                    'overdue_count' => $overdueDebts->count(),
                ],
            ],
        ]);
    }

    /**
     * Round 3 Phase 12: "Invoices" and "Debtors Ledger" on the Driver
     * dashboard, scoped to their own sales only -- not the company-wide
     * versions Manager/Director see under Finance/Sales. There's no
     * formal Invoice record for trip sales (Round 2 Phase 7: trip-side
     * debt is informal shop credit, no invoice generated), so this is
     * genuinely "my sales" rather than a real invoice list -- the
     * frontend labels it accordingly instead of implying documents that
     * don't exist. ?debt_only=1 is the same data filtered down to just
     * the credit sales, which doubles as this driver's own Debtors
     * Ledger view.
     */
    public function mySales(Request $request)
    {
        $userId = Auth::id();

        $query = DriverTripSale::with(['customer', 'trip', 'debt'])
            ->whereNull('deleted_at')
            ->whereHas('trip', fn ($q) => $q->where('driver_id', $userId));

        if ($request->boolean('debt_only')) {
            $query->whereNotNull('debt_id');
        }

        $sales = $query->orderByDesc('created_at')->paginate($request->get('limit', 20));

        $items = collect($sales->items())->map(fn ($s) => [
            'id' => $s->id,
            'trip_date' => optional($s->trip)->trip_date,
            'customer' => $s->customer ? ['id' => $s->customer->id, 'name' => $s->customer->name] : null,
            'payment_method' => $s->payment_method,
            'amount' => (float) $s->amount,
            'physical_receipt_no' => $s->physical_receipt_no,
            'debt' => $s->debt ? [
                'balance' => (float) $s->debt->balance,
                'expected_repayment_date' => $s->debt_expected_repayment_date,
                'signatory' => $s->debt_signatory,
                'days_overdue' => $s->debt->days_overdue,
            ] : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
                'last_page' => $sales->lastPage(),
            ],
        ]);
    }

    /**
     * Ops brief §2.5: customer base for route planning — customers this
     * driver registered or sold to (central CRM subset, not company-wide).
     */
    public function myCustomers(Request $request)
    {
        $userId = Auth::id();

        $soldToIds = DriverTripSale::whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->whereHas('trip', fn ($q) => $q->where('driver_id', $userId))
            ->pluck('customer_id');

        $customers = Customer::whereNull('deleted_at')
            ->where(function ($q) use ($userId, $soldToIds) {
                $q->where('created_by', $userId)
                    ->orWhereIn('id', $soldToIds);
            })
            ->orderBy('name')
            ->paginate($request->get('limit', 50));

        $items = collect($customers->items())->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'type' => $c->type,
            'address' => $c->address,
            'created_by_me' => $c->created_by === $userId,
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    /**
     * Ops brief §2.5: operational KPIs — km, sell-through, fuel, repairs,
     * flagged discrepancies / open issues for this driver.
     */
    public function opsKpis(Request $request)
    {
        $userId = Auth::id();
        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();

        $monthTrips = DriverTrip::with('items')
            ->where('driver_id', $userId)
            ->where('trip_date', '>=', $monthStart)
            ->get();

        $dispatched = (int) $monthTrips->sum(fn ($t) => $t->items->sum('qty_carried_bales'));
        $sold = (int) DriverTripSaleItem::join('driver_trip_sales', 'driver_trip_sales.id', '=', 'driver_trip_sale_items.driver_trip_sale_id')
            ->join('driver_trips', 'driver_trips.id', '=', 'driver_trip_sales.driver_trip_id')
            ->where('driver_trips.driver_id', $userId)
            ->where('driver_trips.trip_date', '>=', $monthStart)
            ->whereNull('driver_trip_sales.deleted_at')
            ->sum('driver_trip_sale_items.qty_bales');
        $returned = max(0, $dispatched - $sold);
        $sellThrough = $dispatched > 0 ? round(($sold / $dispatched) * 100, 1) : null;

        $activeTrip = DriverTrip::with('items')
            ->where('driver_id', $userId)
            ->where('status', 'in_transit')
            ->first();

        $activeDispatched = $activeTrip ? (int) $activeTrip->items->sum('qty_carried_bales') : 0;
        $activeSold = 0;
        if ($activeTrip) {
            $activeSold = (int) DriverTripSaleItem::join('driver_trip_sales', 'driver_trip_sales.id', '=', 'driver_trip_sale_items.driver_trip_sale_id')
                ->where('driver_trip_sales.driver_trip_id', $activeTrip->id)
                ->whereNull('driver_trip_sales.deleted_at')
                ->sum('driver_trip_sale_items.qty_bales');
        }

        $vehicleIds = DriverAssignment::where('driver_id', $userId)
            ->whereNull('end_date')
            ->pluck('vehicle_id');
        // Also include vehicles used on this month's trips.
        $vehicleIds = $vehicleIds->merge($monthTrips->pluck('vehicle_id'))->filter()->unique()->values();

        $fuelMonth = (float) FuelLog::whereNull('deleted_at')
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('date', '>=', $monthStart)
            ->where('date', '<=', $today)
            ->sum('cost');

        $fuelActiveTrip = 0.0;
        if ($activeTrip && $activeTrip->vehicle_id) {
            $fuelActiveTrip = (float) FuelLog::whereNull('deleted_at')
                ->where('vehicle_id', $activeTrip->vehicle_id)
                ->where('date', $activeTrip->trip_date?->toDateString() ?? $activeTrip->trip_date)
                ->sum('cost');
            // Fallback to trip fuel_cost if fuel_logs empty for that day.
            if ($fuelActiveTrip <= 0 && $activeTrip->fuel_cost) {
                $fuelActiveTrip = (float) $activeTrip->fuel_cost;
            }
        }

        $repairs = VehicleRepair::whereNull('deleted_at')
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderByDesc('repair_date')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'repair_date' => optional($r->repair_date)->toDateString(),
                'category' => $r->category,
                'description' => $r->description,
                'cost' => (float) $r->cost,
                'vehicle_id' => $r->vehicle_id,
            ]);

        $repairsMonthCost = (float) VehicleRepair::whereNull('deleted_at')
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('repair_date', '>=', $monthStart)
            ->sum('cost');

        $flaggedTrips = DriverTrip::where('driver_id', $userId)
            ->where('has_discrepancy', true)
            ->orderByDesc('trip_date')
            ->limit(10)
            ->get(['id', 'trip_date', 'route', 'status', 'has_discrepancy']);

        $openIssues = Issue::where('created_by', $userId)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'subject', 'status', 'created_at']);

        $myDiscrepancies = Discrepancy::where('driver_id', $userId)
            ->where('status', 'open')
            ->orderByDesc('date')
            ->limit(10)
            ->get(['id', 'date', 'category', 'status', 'description', 'driver_trip_id']);

        return response()->json([
            'success' => true,
            'data' => [
                'this_month' => [
                    'km_covered' => (int) $monthTrips->sum('km_covered'),
                    'bales_dispatched' => $dispatched,
                    'bales_sold' => $sold,
                    'bales_returned' => $returned,
                    'sell_through_pct' => $sellThrough,
                    'fuel_cost' => round($fuelMonth, 2),
                    'repair_cost' => round($repairsMonthCost, 2),
                ],
                'active_trip' => $activeTrip ? [
                    'id' => $activeTrip->id,
                    'km_covered' => $activeTrip->km_covered,
                    'bales_dispatched' => $activeDispatched,
                    'bales_sold' => $activeSold,
                    'bales_returned' => max(0, $activeDispatched - $activeSold),
                    'sell_through_pct' => $activeDispatched > 0
                        ? round(($activeSold / $activeDispatched) * 100, 1)
                        : null,
                    'fuel_cost' => round($fuelActiveTrip, 2),
                ] : null,
                'repairs' => $repairs,
                'flagged_trips' => $flaggedTrips,
                'open_issues' => $openIssues,
                'discrepancies' => $myDiscrepancies,
            ],
        ]);
    }
}

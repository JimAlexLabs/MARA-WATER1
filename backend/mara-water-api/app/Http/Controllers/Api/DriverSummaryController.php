<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\DriverTrip;
use App\Models\DriverTripItem;
use App\Models\DriverTripSale;
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
                ->selectRaw('SUM(driver_trip_items.qty_carried_bales - driver_trip_items.qty_returned_bales) as net_bales')
                ->value('net_bales');

            return ['kes' => round($kes, 2), 'bales' => $bales];
        };

        // Quantity sold by brand/size -- their currently active trip (if
        // any) and their all-time cumulative, both from qty_sold (the
        // reported-sold basis, same one the reconciliation accessor uses
        // -- see DriverTripItem model docblock).
        $activeTrip = DriverTrip::where('driver_id', $userId)->where('status', 'in_transit')->first();
        $currentTripQty = $activeTrip
            ? DriverTripItem::with('sku')->where('driver_trip_id', $activeTrip->id)
                ->get()->map(fn ($i) => ['sku_id' => $i->sku_id, 'name' => $i->sku->name ?? 'Item', 'brand' => $i->sku->brand ?? null, 'qty_carried' => $i->qty_carried])
                ->values()
            : [];

        $cumulativeQty = DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
            ->join('skus', 'skus.id', '=', 'driver_trip_items.sku_id')
            ->where('driver_trips.driver_id', $userId)
            ->selectRaw('driver_trip_items.sku_id, skus.name, skus.brand, SUM(driver_trip_items.qty_sold) as qty_sold')
            ->groupBy('driver_trip_items.sku_id', 'skus.name', 'skus.brand')
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
}

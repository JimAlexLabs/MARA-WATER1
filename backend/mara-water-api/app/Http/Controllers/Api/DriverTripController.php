<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\DriverTrip;
use App\Models\FuelLog;
use App\Models\Route as RouteModel;
use App\Models\Sku;

class DriverTripController extends Controller
{
    private const WITH = ['driver', 'vehicle', 'route', 'warehouse', 'authorizingOfficer', 'items.sku', 'sales.customer', 'sales.debt'];

    public function index(Request $request)
    {
        try {
            $query = DriverTrip::with(self::WITH);

            if ($request->filled('vehicle_id')) {
                $query->where('vehicle_id', $request->vehicle_id);
            }
            if ($request->filled('driver_id')) {
                $query->where('driver_id', $request->driver_id);
            }
            if ($request->filled('date_from')) {
                $query->whereDate('trip_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('trip_date', '<=', $request->date_to);
            }
            if ($request->filled('mismatched') && $request->boolean('mismatched')) {
                // Filtered in PHP below (reconciliation is a computed accessor,
                // not a column) -- trip volumes here don't warrant a raw SQL
                // version of this yet.
            }

            $trips = $query->orderByDesc('trip_date')->orderByDesc('created_at')
                ->paginate($request->get('limit', 20));

            $items = collect($trips->items());
            if ($request->filled('mismatched') && $request->boolean('mismatched')) {
                $items = $items->filter(fn ($t) => !$t->reconciliation['matches'])->values();
            }

            return response()->json([
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'current_page' => $trips->currentPage(),
                    'per_page' => $trips->perPage(),
                    'total' => $trips->total(),
                    'last_page' => $trips->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve trips',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'trip_date' => 'required|date',
            'driver_id' => 'required|exists:users,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'route_id' => 'nullable|exists:routes,id',
            // Round 2 Phase 8: the depot the vehicle loaded stock from and
            // returns unsold stock to -- required so the trip's stock
            // movements can post to the real ledger the moment it's logged.
            'warehouse_id' => 'required|exists:warehouses,id',
            'mileage_start' => 'nullable|integer|min:0',
            'mileage_end' => 'nullable|integer|min:0|gte:mileage_start',
            'fuel_liters' => 'nullable|numeric|min:0',
            'fuel_cost' => 'nullable|numeric|min:0',
            // Round 2 Phase 7: Oil (Litres) dropped entirely, per the spec.
            'authorizing_officer_id' => 'nullable|exists:users,id',
            'time_out' => 'nullable|date_format:H:i',
            'time_in' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:1000',
            'items' => 'nullable|array',
            'items.*.sku_id' => 'required_with:items|exists:skus,id',
            'items.*.qty_carried' => 'nullable|integer|min:0',
            'items.*.qty_returned' => 'nullable|integer|min:0',
            'items.*.qty_sold' => 'nullable|integer|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            // Round 2 Phase 7: replaces the old single cash_collected/
            // mpesa_collected/debt_* trip totals -- one row per sale made
            // on the trip (one per customer/stop), "traceable to a real
            // buyer, not just a total".
            'sales' => 'nullable|array',
            'sales.*.customer_id' => 'required_with:sales|exists:customers,id',
            'sales.*.payment_method' => 'required_with:sales|in:cash,mpesa,debt',
            'sales.*.amount' => 'required_with:sales|numeric|min:0.01',
            'sales.*.mpesa_reference' => 'nullable|string|max:100',
            'sales.*.debt_signatory' => 'required_if:sales.*.payment_method,debt|nullable|string|max:150',
            'sales.*.debt_expected_repayment_date' => 'required_if:sales.*.payment_method,debt|nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Round 2 Phase 6/7: "never more than 7 days from the sale date",
        // checked per sale so the message can point at exactly which one.
        $tripDate = \Carbon\Carbon::parse($request->trip_date)->startOfDay();
        foreach ($request->input('sales', []) as $i => $sale) {
            if (($sale['payment_method'] ?? null) !== 'debt') {
                continue;
            }
            $repaymentDate = \Carbon\Carbon::parse($sale['debt_expected_repayment_date'])->startOfDay();
            if ($repaymentDate->lt($tripDate)) {
                return response()->json([
                    'success' => false,
                    'message' => "Sale #" . ($i + 1) . ": expected repayment date cannot be before the trip date",
                    'errors' => ["sales.{$i}.debt_expected_repayment_date" => ['Cannot be before the trip date']],
                ], 422);
            }
            if ($repaymentDate->gt($tripDate->copy()->addDays(7))) {
                return response()->json([
                    'success' => false,
                    'message' => "Sale #" . ($i + 1) . ": expected repayment date cannot be more than 7 days from the trip date",
                    'errors' => ["sales.{$i}.debt_expected_repayment_date" => ['Cannot be more than 7 days from the trip date']],
                ], 422);
            }
        }

        try {
            $stockWarnings = [];
            $trip = DB::transaction(function () use ($request, &$stockWarnings) {
                $trip = DriverTrip::create([
                    'trip_date' => $request->trip_date,
                    'driver_id' => $request->driver_id,
                    'vehicle_id' => $request->vehicle_id,
                    'route_id' => $request->route_id,
                    'warehouse_id' => $request->warehouse_id,
                    'mileage_start' => $request->mileage_start,
                    'mileage_end' => $request->mileage_end,
                    'fuel_liters' => $request->fuel_liters,
                    'fuel_cost' => $request->fuel_cost,
                    'authorizing_officer_id' => $request->authorizing_officer_id,
                    'time_out' => $request->time_out,
                    'time_in' => $request->time_in,
                    'notes' => $request->notes,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                // Round 2 Phase 8: stock leaves/returns to this warehouse the
                // moment the trip is logged -- one single stock ledger, the
                // same recordMove()/deductStock() every other sales entry
                // point (OrderController::logSale()) writes to, so Inventory
                // Management reflects this trip in real time, not after a
                // separate manual adjustment.
                $inventoryController = new InventoryController();

                foreach ($request->input('items', []) as $item) {
                    $qtyCarried = $item['qty_carried'] ?? 0;
                    $qtyReturned = $item['qty_returned'] ?? 0;
                    $qtySold = $item['qty_sold'] ?? 0;
                    if ($qtyCarried == 0 && $qtyReturned == 0 && $qtySold == 0) {
                        continue;
                    }
                    $trip->items()->create([
                        'sku_id' => $item['sku_id'],
                        'qty_carried' => $qtyCarried,
                        'qty_returned' => $qtyReturned,
                        'qty_sold' => $qtySold,
                        'unit_price' => $item['unit_price'] ?? 0,
                    ]);

                    $sku = Sku::find($item['sku_id']);
                    $uom = $sku->unit ?? 'BOTTLE';

                    // What came back goes straight into recorded stock at
                    // this warehouse -- same "return" move type, same
                    // no-specific-batch bucket, as an outlet sale's return.
                    if ($qtyReturned > 0) {
                        $inventoryController->recordMove([
                            'move_type' => 'return',
                            'item_type' => 'sku',
                            'sku_id' => $item['sku_id'],
                            'warehouse_to_id' => $request->warehouse_id,
                            'qty' => $qtyReturned,
                            'uom' => $uom,
                            'ref_entity' => 'driver_trip',
                            'ref_id' => $trip->id,
                        ]);
                    }

                    // What didn't come back permanently left the warehouse
                    // the moment it was loaded onto the vehicle -- deducted
                    // here regardless of whether the driver's reported
                    // qty_sold matches (a mismatch there is a reconciliation
                    // signal, not a reason to under- or over-deduct real
                    // physical stock).
                    $netDispatched = max(0, $qtyCarried - $qtyReturned);
                    if ($netDispatched > 0) {
                        $stockWarnings = array_merge(
                            $stockWarnings,
                            $inventoryController->deductStock($item['sku_id'], $sku, $request->warehouse_id, $netDispatched, 'driver_trip', $trip->id)
                        );
                    }
                }

                foreach ($request->input('sales', []) as $sale) {
                    $debtId = null;

                    // Round 2 Phase 6/7: a trip-side debt is informal shop
                    // credit -- no formal invoice, just a named, accountable
                    // signatory -- so it posts straight to Debt + the
                    // debtor ledger the same way OrderController::logSale()'s
                    // credit sales do, just without an Invoice record.
                    if ($sale['payment_method'] === 'debt') {
                        $debt = \App\Models\Debt::create([
                            'customer_id' => $sale['customer_id'],
                            'invoice_id' => null,
                            'signatory' => $sale['debt_signatory'],
                            'expected_repayment_date' => $sale['debt_expected_repayment_date'],
                            'principal' => $sale['amount'],
                            'balance' => $sale['amount'],
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                        ]);
                        \App\Models\DebtorLedgerEntry::create([
                            'customer_id' => $sale['customer_id'],
                            'debt_id' => $debt->id,
                            'entry_date' => $request->trip_date,
                            'details' => 'Credit sale via driver trip -- signed for by ' . $sale['debt_signatory'],
                            'debit' => $sale['amount'],
                            'credit' => 0,
                            'created_by' => Auth::id(),
                            'updated_by' => Auth::id(),
                        ]);
                        $debtId = $debt->id;
                    }

                    $trip->sales()->create([
                        'customer_id' => $sale['customer_id'],
                        'payment_method' => $sale['payment_method'],
                        'amount' => $sale['amount'],
                        'mpesa_reference' => $sale['mpesa_reference'] ?? null,
                        'debt_signatory' => $sale['payment_method'] === 'debt' ? $sale['debt_signatory'] : null,
                        'debt_expected_repayment_date' => $sale['payment_method'] === 'debt' ? $sale['debt_expected_repayment_date'] : null,
                        'debt_id' => $debtId,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);
                }

                // Feed the same fuel_logs table the Dashboard's fuel-cost
                // trend already reads from, so trip logging is the one place
                // fuel gets recorded rather than a second, disconnected form.
                if ($request->filled('fuel_liters') && $request->fuel_liters > 0) {
                    FuelLog::create([
                        'vehicle_id' => $request->vehicle_id,
                        'date' => $request->trip_date,
                        'liters' => $request->fuel_liters,
                        'cost' => $request->fuel_cost ?? 0,
                        'odometer' => $request->mileage_end ?? $request->mileage_start,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);
                }

                return $trip;
            });

            return response()->json([
                'success' => true,
                'message' => 'Trip logged successfully',
                'data' => $trip->load(self::WITH),
                'stock_warnings' => $stockWarnings,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to log trip',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $trip = DriverTrip::with(self::WITH)->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $trip]);
    }

    /**
     * Mainly for closing out an open trip (mileage_end, time_in) or fixing
     * a typo -- not for changing the stock lines or sales, which stay
     * create-once to keep the reconciliation trail honest. Delete and
     * re-log instead (Round 2 Phase 7: cash/M-Pesa/debt totals moved to
     * the per-sale driver_trip_sales table, so they're no longer editable
     * trip-level fields here).
     */
    public function update(Request $request, $id)
    {
        $trip = DriverTrip::find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'mileage_end' => 'nullable|integer|min:0|gte:mileage_start',
            'time_in' => 'nullable|date_format:H:i',
            'fuel_liters' => 'nullable|numeric|min:0',
            'fuel_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $trip->update(array_merge(
            $request->only(['mileage_end', 'time_in', 'fuel_liters', 'fuel_cost', 'notes']),
            ['updated_by' => Auth::id()]
        ));

        return response()->json([
            'success' => true,
            'message' => 'Trip updated successfully',
            'data' => $trip->load(self::WITH),
        ]);
    }

    public function destroy($id)
    {
        $trip = DriverTrip::find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }

        DB::transaction(function () use ($trip) {
            $this->reverseStockMoves($trip);
            $trip->delete();
        });

        return response()->json(['success' => true, 'message' => 'Trip deleted successfully']);
    }

    /**
     * Round 2 Phase 8: Phase 7's own doc comment on update() says "Delete
     * and re-log instead" for correcting a trip's stock lines -- so
     * deletion has to give back exactly what this trip's stock moves did,
     * or "delete and re-log" would silently double-deduct every time
     * someone corrects a mistake. Reverses each move this trip posted
     * (an 'issue' gets its qty added back, a 'return' gets it taken back
     * out) as new 'adjust' moves tagged 'driver_trip_deleted' so the
     * ledger keeps a clean, traceable record of the reversal rather than
     * just deleting the original entries. allowNegative on the 'return'
     * reversal because that stock could theoretically have already been
     * sold on since -- a correction should never be blocked by that.
     */
    private function reverseStockMoves(DriverTrip $trip): void
    {
        $inventoryController = new InventoryController();
        $moves = \App\Models\StockMove::where('ref_entity', 'driver_trip')
            ->where('ref_id', $trip->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($moves as $move) {
            if ($move->move_type === 'issue' && $move->warehouse_from_id) {
                $inventoryController->recordMove([
                    'move_type' => 'adjust',
                    'item_type' => $move->item_type,
                    'sku_id' => $move->sku_id,
                    'batch_id' => $move->batch_id,
                    'warehouse_to_id' => $move->warehouse_from_id,
                    'qty' => $move->qty,
                    'uom' => $move->uom,
                    'ref_entity' => 'driver_trip_deleted',
                    'ref_id' => $trip->id,
                ]);
            } elseif ($move->move_type === 'return' && $move->warehouse_to_id) {
                $inventoryController->recordMove([
                    'move_type' => 'adjust',
                    'item_type' => $move->item_type,
                    'sku_id' => $move->sku_id,
                    'batch_id' => $move->batch_id,
                    'warehouse_from_id' => $move->warehouse_to_id,
                    'qty' => $move->qty,
                    'uom' => $move->uom,
                    'ref_entity' => 'driver_trip_deleted',
                    'ref_id' => $trip->id,
                ], allowNegative: true);
            }
        }
    }

    public function statistics(Request $request)
    {
        // sales eager-loaded too -- total_collected/reconciliation are
        // computed accessors that read $this->sales, and without this
        // each trip would lazy-load its own sales query (N+1). items.sku
        // likewise, for the stock-mismatch sku_name lookup.
        $query = DriverTrip::with(['items.sku', 'sales']);
        if ($request->filled('date_from')) {
            $query->whereDate('trip_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('trip_date', '<=', $request->date_to);
        }
        $trips = $query->get();

        $mismatched = $trips->filter(fn ($t) => !$t->reconciliation['matches']);

        return response()->json([
            'success' => true,
            'data' => [
                'total_trips' => $trips->count(),
                'total_km' => $trips->sum('km_covered'),
                'total_fuel_liters' => (float) $trips->sum('fuel_liters'),
                'total_fuel_cost' => (float) $trips->sum('fuel_cost'),
                'total_collected' => round($trips->sum('total_collected'), 2),
                'mismatched_trips' => $mismatched->count(),
                'mismatched_variance_total' => round($mismatched->sum(fn ($t) => $t->reconciliation['variance']), 2),
            ],
        ]);
    }

    /**
     * Round 2 Phase 7: one-click CSV (opens straight in Excel) export of
     * the trip log -- "every column above": a column per active SKU for
     * dispatched/returned/sold, plus payment method breakdown, buyer
     * name/contact, and debt details when applicable. One row per sale on
     * a trip (a trip with 3 sales prints 3 rows, with the trip-level and
     * per-SKU columns repeated on each); a trip with no sales logged yet
     * prints one row with the buyer/payment columns blank. Columns come
     * from whatever SKUs are actually active right now rather than a
     * hardcoded product list, so the export can't drift from Products &
     * Prices (Phase 10) as the catalog changes.
     */
    public function export(Request $request)
    {
        $query = DriverTrip::with(self::WITH);

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('driver_id')) {
            $query->where('driver_id', $request->driver_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('trip_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('trip_date', '<=', $request->date_to);
        }

        $trips = $query->orderBy('trip_date')->orderBy('created_at')->get();
        $skus = Sku::where('active', true)->orderBy('brand')->orderBy('name')->get();

        $header = [
            'Trip Date', 'Driver', 'Vehicle', 'Route', 'Warehouse',
            'Mileage Start', 'Mileage End', 'KM Covered',
            'Fuel Liters', 'Fuel Cost', 'Authorizing Officer', 'Time Out', 'Time In',
        ];
        foreach ($skus as $sku) {
            $label = trim(($sku->brand ? $sku->brand . ' ' : '') . $sku->name);
            $header[] = "{$label} Dispatched";
            $header[] = "{$label} Returned";
            $header[] = "{$label} Sold";
        }
        $header = array_merge($header, [
            'Buyer', 'Buyer Contact', 'Payment Method', 'Amount',
            'M-Pesa Reference', 'Debt Signatory', 'Debt Expected Repayment Date',
            'Trip Total Collected', 'Cash Collected', 'M-Pesa Collected', 'Debt Collected',
            'Expected Revenue', 'Variance', 'Stock Matches', 'Notes',
        ]);

        $escape = fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';
        $rows = [implode(',', array_map($escape, $header))];

        foreach ($trips as $trip) {
            $itemsBySkuId = $trip->items->keyBy('sku_id');
            $recon = $trip->reconciliation;

            $base = [
                $trip->trip_date->toDateString(),
                $trip->driver->full_name ?? '',
                $trip->vehicle->reg_no ?? '',
                $trip->route->name ?? '',
                $trip->warehouse->name ?? '',
                $trip->mileage_start,
                $trip->mileage_end,
                $trip->km_covered,
                $trip->fuel_liters,
                $trip->fuel_cost,
                $trip->authorizingOfficer->full_name ?? '',
                $trip->time_out,
                $trip->time_in,
            ];
            foreach ($skus as $sku) {
                $item = $itemsBySkuId->get($sku->id);
                $base[] = $item->qty_carried ?? 0;
                $base[] = $item->qty_returned ?? 0;
                $base[] = $item->qty_sold ?? 0;
            }

            $trailer = [
                $trip->total_collected,
                $trip->cash_collected,
                $trip->mpesa_collected,
                $trip->debt_collected,
                $recon['expected_revenue'],
                $recon['variance'],
                $recon['stock_matches'] ? 'Yes' : 'No',
                $trip->notes,
            ];

            if ($trip->sales->isEmpty()) {
                $rows[] = implode(',', array_map($escape, array_merge($base, ['', '', '', '', '', '', ''], $trailer)));
                continue;
            }

            foreach ($trip->sales as $sale) {
                $row = array_merge($base, [
                    $sale->customer->name ?? '',
                    $sale->customer->phone ?? '',
                    ucfirst($sale->payment_method),
                    $sale->amount,
                    $sale->mpesa_reference,
                    $sale->debt_signatory,
                    optional($sale->debt_expected_repayment_date)->toDateString(),
                ], $trailer);
                $rows[] = implode(',', array_map($escape, $row));
            }
        }

        $csv = implode("\n", $rows);
        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="driver-trip-log-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * Mileage-per-vehicle history and trend, for maintenance scheduling and
     * fuel-efficiency tracking.
     */
    public function mileageTrend(Request $request)
    {
        $days = min((int) $request->get('days', 30), 180);
        $since = now()->subDays($days)->toDateString();

        $trips = DriverTrip::with('vehicle')
            ->whereDate('trip_date', '>=', $since)
            ->whereNotNull('mileage_start')->whereNotNull('mileage_end')
            ->orderBy('trip_date')
            ->get();

        $byVehicle = $trips->groupBy('vehicle_id')->map(function ($vehicleTrips) {
            $vehicle = $vehicleTrips->first()->vehicle;
            return [
                'vehicle_id' => $vehicle->id ?? null,
                'reg_no' => $vehicle->reg_no ?? 'Unknown',
                'total_km' => $vehicleTrips->sum('km_covered'),
                'total_fuel_liters' => (float) $vehicleTrips->sum('fuel_liters'),
                'trips' => $vehicleTrips->count(),
                'km_per_liter' => (float) $vehicleTrips->sum('fuel_liters') > 0
                    ? round($vehicleTrips->sum('km_covered') / (float) $vehicleTrips->sum('fuel_liters'), 2)
                    : null,
                'daily' => $vehicleTrips->groupBy(fn ($t) => $t->trip_date->toDateString())
                    ->map(fn ($dayTrips, $date) => ['date' => $date, 'km' => $dayTrips->sum('km_covered')])
                    ->values(),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $byVehicle]);
    }

    // --- Routes reference list (towns/zones drivers pick from) ---

    public function routes()
    {
        return response()->json(['success' => true, 'data' => RouteModel::orderBy('name')->get()]);
    }

    // Product catalog for the stock-carried/returned lines on the trip form.
    /**
     * Round 2 Phase 10: current_price comes from the default price list
     * ("Products & Prices") -- the trip form pre-fills each row's unit
     * price from this instead of a driver having to know/type it, per
     * "single source of truth ... Sales, Driver Trip Logs ... pull unit
     * prices from".
     */
    public function skus()
    {
        $prices = \App\Models\PriceListItem::whereHas('priceList', fn ($q) => $q->where('is_default', true))
            ->pluck('unit_price', 'sku_id');

        $skus = Sku::where('active', true)->orderBy('name')->get()->map(function ($sku) use ($prices) {
            $sku->current_price = isset($prices[$sku->id]) ? (float) $prices[$sku->id] : null;
            return $sku;
        });

        return response()->json(['success' => true, 'data' => $skus]);
    }

    public function storeRoute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:routes,name',
            'description' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $route = RouteModel::create([
            'name' => $request->name,
            'description' => $request->description,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'data' => $route], 201);
    }
}

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
    private const WITH = ['driver', 'vehicle', 'route', 'authorizingOfficer', 'items.sku', 'debtCustomer'];

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
            'mileage_start' => 'nullable|integer|min:0',
            'mileage_end' => 'nullable|integer|min:0|gte:mileage_start',
            'fuel_liters' => 'nullable|numeric|min:0',
            'fuel_cost' => 'nullable|numeric|min:0',
            'oil_liters' => 'nullable|numeric|min:0',
            'authorizing_officer_id' => 'nullable|exists:users,id',
            'time_out' => 'nullable|date_format:H:i',
            'time_in' => 'nullable|date_format:H:i',
            'cash_collected' => 'nullable|numeric|min:0',
            'mpesa_collected' => 'nullable|numeric|min:0',
            'mpesa_reference' => 'nullable|string|max:100',
            // Round 2 Phase 6: "specifically to Log Driver Trip, since
            // drivers are the ones extending credit in the field." One
            // optional debt sale per trip -- required together whenever an
            // amount is entered, so a debt can't be logged with no one
            // accountable for it.
            'debt_customer_id' => 'required_with:debt_amount|nullable|exists:customers,id',
            'debt_signatory' => 'required_with:debt_amount|nullable|string|max:150',
            'debt_amount' => 'nullable|numeric|min:0',
            'debt_expected_repayment_date' => 'required_with:debt_amount|nullable|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'nullable|array',
            'items.*.sku_id' => 'required_with:items|exists:skus,id',
            'items.*.qty_carried' => 'nullable|integer|min:0',
            'items.*.qty_returned' => 'nullable|integer|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->filled('debt_amount') && (float) $request->debt_amount > 0) {
            $tripDate = \Carbon\Carbon::parse($request->trip_date)->startOfDay();
            $repaymentDate = \Carbon\Carbon::parse($request->debt_expected_repayment_date)->startOfDay();
            if ($repaymentDate->lt($tripDate)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expected repayment date cannot be before the trip date',
                    'errors' => ['debt_expected_repayment_date' => ['Cannot be before the trip date']],
                ], 422);
            }
            if ($repaymentDate->gt($tripDate->copy()->addDays(7))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expected repayment date cannot be more than 7 days from the trip date',
                    'errors' => ['debt_expected_repayment_date' => ['Cannot be more than 7 days from the trip date']],
                ], 422);
            }
        }

        try {
            $trip = DB::transaction(function () use ($request) {
                $debtAmount = (float) ($request->debt_amount ?? 0);
                $debtId = null;

                // Round 2 Phase 6: a trip-side debt is informal shop credit
                // -- no formal invoice, just a named, accountable signatory
                // -- so it posts straight to Debt + the debtor ledger the
                // same way OrderController::logSale()'s credit sales do,
                // just without an Invoice record.
                if ($debtAmount > 0) {
                    $debt = \App\Models\Debt::create([
                        'customer_id' => $request->debt_customer_id,
                        'invoice_id' => null,
                        'signatory' => $request->debt_signatory,
                        'expected_repayment_date' => $request->debt_expected_repayment_date,
                        'principal' => $debtAmount,
                        'balance' => $debtAmount,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);
                    \App\Models\DebtorLedgerEntry::create([
                        'customer_id' => $request->debt_customer_id,
                        'debt_id' => $debt->id,
                        'entry_date' => $request->trip_date,
                        'details' => 'Credit sale via driver trip -- signed for by ' . $request->debt_signatory,
                        'debit' => $debtAmount,
                        'credit' => 0,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);
                    $debtId = $debt->id;
                }

                $trip = DriverTrip::create([
                    'trip_date' => $request->trip_date,
                    'driver_id' => $request->driver_id,
                    'vehicle_id' => $request->vehicle_id,
                    'route_id' => $request->route_id,
                    'mileage_start' => $request->mileage_start,
                    'mileage_end' => $request->mileage_end,
                    'fuel_liters' => $request->fuel_liters,
                    'fuel_cost' => $request->fuel_cost,
                    'oil_liters' => $request->oil_liters,
                    'authorizing_officer_id' => $request->authorizing_officer_id,
                    'time_out' => $request->time_out,
                    'time_in' => $request->time_in,
                    'cash_collected' => $request->cash_collected ?? 0,
                    'debt_customer_id' => $debtAmount > 0 ? $request->debt_customer_id : null,
                    'debt_signatory' => $debtAmount > 0 ? $request->debt_signatory : null,
                    'debt_amount' => $debtAmount,
                    'debt_expected_repayment_date' => $debtAmount > 0 ? $request->debt_expected_repayment_date : null,
                    'debt_id' => $debtId,
                    'mpesa_collected' => $request->mpesa_collected ?? 0,
                    'mpesa_reference' => $request->mpesa_reference,
                    'notes' => $request->notes,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                foreach ($request->input('items', []) as $item) {
                    if (($item['qty_carried'] ?? 0) == 0 && ($item['qty_returned'] ?? 0) == 0) {
                        continue;
                    }
                    $trip->items()->create([
                        'sku_id' => $item['sku_id'],
                        'qty_carried' => $item['qty_carried'] ?? 0,
                        'qty_returned' => $item['qty_returned'] ?? 0,
                        'unit_price' => $item['unit_price'] ?? 0,
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
     * a typo -- not for changing the stock lines, which stay create-once
     * to keep the reconciliation trail honest. Delete and re-log instead.
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
            'cash_collected' => 'nullable|numeric|min:0',
            'mpesa_collected' => 'nullable|numeric|min:0',
            'mpesa_reference' => 'nullable|string|max:100',
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
            $request->only(['mileage_end', 'time_in', 'cash_collected', 'mpesa_collected', 'mpesa_reference', 'notes']),
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
        $trip->delete();
        return response()->json(['success' => true, 'message' => 'Trip deleted successfully']);
    }

    public function statistics(Request $request)
    {
        $query = DriverTrip::with('items');
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
    public function skus()
    {
        return response()->json([
            'success' => true,
            'data' => Sku::where('active', true)->orderBy('name')->get(),
        ]);
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

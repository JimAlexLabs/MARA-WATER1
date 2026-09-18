<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\DriverTrip;
use App\Models\DriverTripUnlock;
use App\Models\FuelLog;
use App\Models\Route as RouteModel;
use App\Models\Sku;

/**
 * Round 3 Phase 2: the trip log is now a staged, lockable workflow
 * instead of a single-shot form -- see DriverTrip::STATUSES. One
 * endpoint per stage transition:
 *   store()   -- stage 1+2 (pending_departure), fully editable
 *   update()  -- edit stage 1+2 fields, pending_departure only
 *   start()   -- stage 3 trigger: locks dispatch, deducts stock
 *   addSale() -- stage 4: one sale at a time against an in_transit trip
 *   end()     -- stage 5 trigger: locks returns, posts return stock,
 *                computes the discrepancy flag
 *   unlock()  -- Director-only, reverses exactly one stage transition,
 *                logs to driver_trip_unlocks
 */
class DriverTripController extends Controller
{
    private const WITH = ['driver', 'vehicle', 'warehouse', 'authorizingOfficer', 'items.sku', 'sales.customer', 'sales.debt', 'sales.items.sku'];

    public function index(Request $request)
    {
        try {
            $query = DriverTrip::with(self::WITH);

            // Round 2 Phase 11: "view their own trip history ... no
            // visibility into other drivers" -- enforced here, not just a
            // hidden filter client-side, so a driver can't see another
            // driver's trips by passing a different driver_id either.
            if ($request->user()->hasAccessTier('driver')) {
                $query->where('driver_id', $request->user()->id);
            } elseif ($request->filled('driver_id')) {
                $query->where('driver_id', $request->driver_id);
            }

            if ($request->filled('vehicle_id')) {
                $query->where('vehicle_id', $request->vehicle_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('date_from')) {
                $query->whereDate('trip_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('trip_date', '<=', $request->date_to);
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

    /**
     * Stage 1+2 -- Pre-departure + Dispatched. Creates a trip in
     * pending_departure status: date/vehicle/route/warehouse/mileage
     * start/authorizing officer, plus the per-brand/size dispatched
     * quantities (bottles and bales -- see the migration docblock for
     * why bales don't convert to/from bottles). Nothing here posts a
     * stock move or touches sales yet -- that's start()/addSale()/end()
     * below. Fully editable via update() until start() locks it.
     */
    public function store(Request $request)
    {
        if ($request->user()->hasAccessTier('driver')) {
            $request->merge(['driver_id' => $request->user()->id]);
        }

        $validator = Validator::make($request->all(), [
            'trip_date' => 'required|date',
            'driver_id' => 'required|exists:users,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'route' => 'required|string|max:255',
            'warehouse_id' => 'required|exists:warehouses,id',
            'mileage_start' => 'required|integer|min:0',
            // Required -- someone has to be accountable for authorizing
            // the dispatch. A Manager creating their own trip defaults to
            // themselves (frontend); a Driver has no "logged-in manager"
            // to default to, so must pick one explicitly.
            'authorizing_officer_id' => 'required|exists:users,id',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.sku_id' => 'required|exists:skus,id',
            'items.*.qty_carried' => 'nullable|integer|min:0',
            'items.*.qty_carried_bales' => 'nullable|integer|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $trip = DB::transaction(function () use ($request) {
            $trip = DriverTrip::create([
                'trip_date' => $request->trip_date,
                'driver_id' => $request->driver_id,
                'vehicle_id' => $request->vehicle_id,
                'route' => $request->route,
                'warehouse_id' => $request->warehouse_id,
                'status' => 'pending_departure',
                'mileage_start' => $request->mileage_start,
                'authorizing_officer_id' => $request->authorizing_officer_id,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            foreach ($request->input('items', []) as $item) {
                $qtyCarried = $item['qty_carried'] ?? 0;
                $qtyCarriedBales = $item['qty_carried_bales'] ?? 0;
                if ($qtyCarried == 0 && $qtyCarriedBales == 0) {
                    continue;
                }
                $trip->items()->create([
                    'sku_id' => $item['sku_id'],
                    'qty_carried' => $qtyCarried,
                    'qty_carried_bales' => $qtyCarriedBales,
                    'unit_price' => $item['unit_price'] ?? 0,
                ]);
            }

            return $trip;
        });

        return response()->json([
            'success' => true,
            'message' => 'Trip created -- pending departure',
            'data' => $trip->load(self::WITH),
        ], 201);
    }

    private function denyIfNotOwnTrip(Request $request, DriverTrip $trip)
    {
        if ($request->user()->hasAccessTier('driver') && $trip->driver_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this trip.'], 403);
        }
        return null;
    }

    public function show(Request $request, $id)
    {
        $trip = DriverTrip::with(self::WITH)->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        return response()->json(['success' => true, 'data' => $trip]);
    }

    /**
     * Stage 1+2 fields only, and only while pending_departure -- once
     * start() locks the trip, these become read-only to everyone below
     * Director (unlock() is the only way back).
     */
    public function update(Request $request, $id)
    {
        $trip = DriverTrip::with('items')->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        if ($trip->status !== 'pending_departure') {
            return response()->json(['success' => false, 'message' => 'This trip is locked -- only pending-departure trips can be edited directly. A Director can unlock it.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'trip_date' => 'sometimes|date',
            'vehicle_id' => 'sometimes|exists:vehicles,id',
            'route' => 'sometimes|string|max:255',
            'warehouse_id' => 'sometimes|exists:warehouses,id',
            'mileage_start' => 'sometimes|integer|min:0',
            'authorizing_officer_id' => 'sometimes|exists:users,id',
            'notes' => 'nullable|string|max:1000',
            'items' => 'sometimes|array',
            'items.*.sku_id' => 'required_with:items|exists:skus,id',
            'items.*.qty_carried' => 'nullable|integer|min:0',
            'items.*.qty_carried_bales' => 'nullable|integer|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $trip) {
            $trip->update(array_merge(
                $request->only(['trip_date', 'vehicle_id', 'route', 'warehouse_id', 'mileage_start', 'authorizing_officer_id', 'notes']),
                ['updated_by' => Auth::id()]
            ));

            if ($request->has('items')) {
                $trip->items()->delete();
                foreach ($request->input('items', []) as $item) {
                    $qtyCarried = $item['qty_carried'] ?? 0;
                    $qtyCarriedBales = $item['qty_carried_bales'] ?? 0;
                    if ($qtyCarried == 0 && $qtyCarriedBales == 0) {
                        continue;
                    }
                    $trip->items()->create([
                        'sku_id' => $item['sku_id'],
                        'qty_carried' => $qtyCarried,
                        'qty_carried_bales' => $qtyCarriedBales,
                        'unit_price' => $item['unit_price'] ?? 0,
                    ]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Trip updated successfully',
            'data' => $trip->fresh(self::WITH),
        ]);
    }

    /**
     * Stage 3 trigger -- "Start Trip". Locks mileage_start + every
     * dispatched quantity, timestamps the departure, and deducts the
     * net-dispatched stock from the warehouse (same deductStock() every
     * other sales entry point uses -- this used to happen inside the old
     * single-shot store(), now it happens exactly when the vehicle
     * actually leaves).
     */
    public function start(Request $request, $id)
    {
        $trip = DriverTrip::with('items.sku')->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        if ($trip->status !== 'pending_departure') {
            return response()->json(['success' => false, 'message' => 'Trip already started'], 422);
        }
        if ($trip->items->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Add at least one dispatched item before starting the trip'], 422);
        }

        $stockWarnings = [];
        DB::transaction(function () use ($trip, &$stockWarnings) {
            $inventoryController = new InventoryController();

            foreach ($trip->items as $item) {
                if ($item->qty_carried <= 0) {
                    continue;
                }
                $stockWarnings = array_merge(
                    $stockWarnings,
                    $inventoryController->deductStock($item->sku_id, $item->sku, $trip->warehouse_id, $item->qty_carried, 'driver_trip', $trip->id)
                );
            }

            $trip->update([
                'status' => 'in_transit',
                'time_out' => now()->format('H:i:s'),
                'locked_at' => now(),
                'updated_by' => Auth::id(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Trip started -- dispatch is now locked',
            'data' => $trip->fresh(self::WITH),
            'stock_warnings' => $stockWarnings,
        ]);
    }

    /**
     * Stage 4 -- one sale at a time against an in_transit trip. Same
     * debt/signatory/7-day-repayment-cap discipline the old single-shot
     * store() enforced, now checked per call instead of per array
     * element. Optional per-brand/size line items (bales) feed the
     * running tally the trip detail view reads.
     */
    public function addSale(Request $request, $id)
    {
        $trip = DriverTrip::find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        if ($trip->status !== 'in_transit') {
            return response()->json(['success' => false, 'message' => 'Sales can only be recorded on a trip that is in transit'], 422);
        }

        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'payment_method' => 'required|in:cash,mpesa,debt,pay_direct',
            'amount' => 'required|numeric|min:0.01',
            // Dual-purpose: M-Pesa code for 'mpesa', QR/reference note for
            // 'pay_direct' -- see DriverTripSale model docblock.
            'mpesa_reference' => 'nullable|string|max:100',
            'debt_signatory' => 'required_if:payment_method,debt|nullable|string|max:150',
            'debt_expected_repayment_date' => 'required_if:payment_method,debt|nullable|date',
            // Round 3 Phase 3: optional paper-book cross-reference -- the
            // driver keeps writing the physical receipt/delivery note as
            // today, this just lets the number also be looked up here.
            'physical_receipt_no' => 'nullable|string|max:50',
            'physical_delivery_note_no' => 'nullable|string|max:50',
            'items' => 'nullable|array',
            'items.*.sku_id' => 'required_with:items|exists:skus,id',
            'items.*.qty_bales' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if ($request->payment_method === 'debt') {
            $tripDate = \Carbon\Carbon::parse($trip->trip_date)->startOfDay();
            $repaymentDate = \Carbon\Carbon::parse($request->debt_expected_repayment_date)->startOfDay();
            if ($repaymentDate->lt($tripDate)) {
                return response()->json(['success' => false, 'message' => 'Expected repayment date cannot be before the trip date', 'errors' => ['debt_expected_repayment_date' => ['Cannot be before the trip date']]], 422);
            }
            if ($repaymentDate->gt($tripDate->copy()->addDays(7))) {
                return response()->json(['success' => false, 'message' => 'Expected repayment date cannot be more than 7 days from the trip date', 'errors' => ['debt_expected_repayment_date' => ['Cannot be more than 7 days from the trip date']]], 422);
            }
        }

        $sale = DB::transaction(function () use ($request, $trip) {
            $debtId = null;

            if ($request->payment_method === 'debt') {
                $debt = \App\Models\Debt::create([
                    'customer_id' => $request->customer_id,
                    'invoice_id' => null,
                    'signatory' => $request->debt_signatory,
                    'expected_repayment_date' => $request->debt_expected_repayment_date,
                    'principal' => $request->amount,
                    'balance' => $request->amount,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                \App\Models\DebtorLedgerEntry::create([
                    'customer_id' => $request->customer_id,
                    'debt_id' => $debt->id,
                    'entry_date' => $trip->trip_date,
                    'details' => 'Credit sale via driver trip -- signed for by ' . $request->debt_signatory,
                    'debit' => $request->amount,
                    'credit' => 0,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                $debtId = $debt->id;
            }

            $sale = $trip->sales()->create([
                'customer_id' => $request->customer_id,
                'payment_method' => $request->payment_method,
                'amount' => $request->amount,
                'mpesa_reference' => $request->mpesa_reference,
                'debt_signatory' => $request->payment_method === 'debt' ? $request->debt_signatory : null,
                'debt_expected_repayment_date' => $request->payment_method === 'debt' ? $request->debt_expected_repayment_date : null,
                'debt_id' => $debtId,
                'physical_receipt_no' => $request->physical_receipt_no,
                'physical_delivery_note_no' => $request->physical_delivery_note_no,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            foreach ($request->input('items', []) as $line) {
                $sale->items()->create([
                    'sku_id' => $line['sku_id'],
                    'qty_bales' => $line['qty_bales'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => round($line['qty_bales'] * $line['unit_price'], 2),
                ]);
            }

            return $sale;
        });

        return response()->json([
            'success' => true,
            'message' => 'Sale recorded',
            'data' => [
                'sale' => $sale->load(['customer', 'debt', 'items.sku']),
                'trip' => $trip->fresh(self::WITH),
            ],
        ], 201);
    }

    /**
     * Stage 5 trigger -- "End Trip". Captures mileage_end + returned
     * quantities (bottles and bales), derives qty_sold per item
     * (carried - returned, same "implied sold" math the reconciliation
     * accessor already did, now the authoritative recorded value since
     * there's no separate manual sold entry anymore), posts the 'return'
     * stock move for what came back, and flags has_discrepancy if the
     * money reconciliation doesn't match. A discrepancy still saves --
     * it's a visible signal, not a block (Phase 2's spec: flag it, don't
     * refuse to close the trip).
     */
    public function end(Request $request, $id)
    {
        $trip = DriverTrip::with('items.sku', 'sales')->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        if ($trip->status !== 'in_transit') {
            return response()->json(['success' => false, 'message' => 'Only a trip that is in transit can be ended'], 422);
        }

        $validator = Validator::make($request->all(), [
            'mileage_end' => 'required|integer|min:' . ($trip->mileage_start ?? 0),
            'fuel_liters' => 'nullable|numeric|min:0',
            'fuel_cost' => 'nullable|numeric|min:0',
            'items' => 'required|array',
            'items.*.sku_id' => 'required|exists:skus,id',
            'items.*.qty_returned' => 'nullable|integer|min:0',
            'items.*.qty_returned_bales' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $returnsBySkuId = collect($request->input('items', []))->keyBy('sku_id');
        $stockWarnings = [];

        DB::transaction(function () use ($request, $trip, $returnsBySkuId, &$stockWarnings) {
            $inventoryController = new InventoryController();

            foreach ($trip->items as $item) {
                $returned = $returnsBySkuId->get($item->sku_id);
                $qtyReturned = min((int) ($returned['qty_returned'] ?? 0), $item->qty_carried);
                $qtyReturnedBales = (int) ($returned['qty_returned_bales'] ?? 0);
                $qtySold = max(0, $item->qty_carried - $qtyReturned);

                $item->update([
                    'qty_returned' => $qtyReturned,
                    'qty_returned_bales' => $qtyReturnedBales,
                    'qty_sold' => $qtySold,
                ]);

                if ($qtyReturned > 0) {
                    $inventoryController->recordMove([
                        'move_type' => 'return',
                        'item_type' => 'sku',
                        'sku_id' => $item->sku_id,
                        'warehouse_to_id' => $trip->warehouse_id,
                        'qty' => $qtyReturned,
                        'uom' => $item->sku->unit ?? 'BOTTLE',
                        'ref_entity' => 'driver_trip',
                        'ref_id' => $trip->id,
                    ]);
                }
            }

            if ($request->filled('fuel_liters') && $request->fuel_liters > 0) {
                FuelLog::create([
                    'vehicle_id' => $trip->vehicle_id,
                    'date' => $trip->trip_date,
                    'liters' => $request->fuel_liters,
                    'cost' => $request->fuel_cost ?? 0,
                    'odometer' => $request->mileage_end,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            $trip->update([
                'mileage_end' => $request->mileage_end,
                'fuel_liters' => $request->fuel_liters,
                'fuel_cost' => $request->fuel_cost,
                'status' => 'completed',
                'time_in' => now()->format('H:i:s'),
                'locked_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            // has_discrepancy is a stored column so Phase 6's dashboard
            // alert can filter on it without loading every trip's full
            // computed reconciliation -- reconciliation itself (the
            // accessor) is recomputed fresh below, after items just
            // changed, so this reads the real post-return numbers.
            $trip->refresh();
            $trip->update(['has_discrepancy' => !$trip->reconciliation['matches']]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Trip closed',
            'data' => $trip->fresh(self::WITH),
            'stock_warnings' => $stockWarnings,
        ]);
    }

    /**
     * Director-only. Reverses exactly one stage transition (the most
     * recent lock), so a genuine mistake can be corrected instead of
     * living in the record forever: completed -> in_transit undoes End
     * Trip (reverses the 'return' stock moves, clears mileage_end/
     * returns so End Trip can be resubmitted); in_transit ->
     * pending_departure undoes Start Trip (reverses the dispatch
     * deduction, clears the lock so items/mileage_start are editable
     * again). Every unlock is logged with who/when/reason and a full
     * snapshot of the trip as it stood before the reversal.
     */
    public function unlock(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasAccessTier('director')) {
            return response()->json(['success' => false, 'message' => 'Only a Director can unlock a trip'], 403);
        }

        $trip = DriverTrip::with(self::WITH)->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($trip->status === 'pending_departure') {
            return response()->json(['success' => false, 'message' => 'This trip is not locked'], 422);
        }

        $validator = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $fromStatus = $trip->status;
        $snapshot = $trip->toArray();

        DB::transaction(function () use ($trip, $fromStatus, $request, $snapshot) {
            if ($fromStatus === 'completed') {
                $this->reverseStockMoves($trip, ['return']);
                foreach ($trip->items as $item) {
                    $item->update(['qty_returned' => 0, 'qty_returned_bales' => 0, 'qty_sold' => 0]);
                }
                $trip->update([
                    'status' => 'in_transit',
                    'mileage_end' => null,
                    'time_in' => null,
                    'has_discrepancy' => false,
                    'updated_by' => Auth::id(),
                ]);
                $toStatus = 'in_transit';
            } else { // in_transit -> pending_departure
                $this->reverseStockMoves($trip, ['issue']);
                $trip->update([
                    'status' => 'pending_departure',
                    'time_out' => null,
                    'locked_at' => null,
                    'updated_by' => Auth::id(),
                ]);
                $toStatus = 'pending_departure';
            }

            DriverTripUnlock::create([
                'driver_trip_id' => $trip->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $request->reason,
                'snapshot' => $snapshot,
                'unlocked_by' => Auth::id(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => "Trip unlocked -- reverted from {$fromStatus} to {$trip->fresh()->status}",
            'data' => $trip->fresh(array_merge(self::WITH, ['unlocks.unlockedBy'])),
        ]);
    }

    /**
     * Only a pending_departure trip can be deleted outright -- it has no
     * stock/sales effects yet. A locked trip has to be unlocked (above)
     * back to pending_departure first, which already reverses whatever
     * it posted, before it can be deleted.
     */
    public function destroy(Request $request, $id)
    {
        $trip = DriverTrip::find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        if ($trip->status !== 'pending_departure') {
            return response()->json(['success' => false, 'message' => 'A locked trip must be unlocked by a Director before it can be deleted'], 422);
        }

        $trip->delete();

        return response()->json(['success' => true, 'message' => 'Trip deleted successfully']);
    }

    /**
     * Round 2 Phase 8 (originally in store()/destroy()): reverses stock
     * moves this trip posted, as new 'adjust' moves tagged
     * 'driver_trip_deleted' -- a clean, traceable reversal rather than
     * deleting the original ledger entries. $moveTypes narrows this to
     * just the moves from one stage transition (unlock() above uses
     * this so undoing End Trip doesn't also undo Start Trip's dispatch
     * deduction); omit it to reverse everything a trip ever posted.
     */
    private function reverseStockMoves(DriverTrip $trip, ?array $moveTypes = null): void
    {
        $inventoryController = new InventoryController();
        $query = \App\Models\StockMove::where('ref_entity', 'driver_trip')
            ->where('ref_id', $trip->id)
            ->whereNull('deleted_at');
        if ($moveTypes) {
            $query->whereIn('move_type', $moveTypes);
        }
        $moves = $query->get();

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
                'pending_departure' => $trips->where('status', 'pending_departure')->count(),
                'in_transit' => $trips->where('status', 'in_transit')->count(),
                'completed' => $trips->where('status', 'completed')->count(),
                'discrepancy_trips' => $trips->where('has_discrepancy', true)->count(),
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
     * name/contact, and debt details when applicable.
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
            'Trip Date', 'Status', 'Driver', 'Vehicle', 'Route', 'Warehouse',
            'Mileage Start', 'Mileage End', 'KM Covered',
            'Fuel Liters', 'Fuel Cost', 'Authorizing Officer', 'Departure Time', 'Return Time',
        ];
        foreach ($skus as $sku) {
            $label = trim(($sku->brand ? $sku->brand . ' ' : '') . $sku->name);
            $header[] = "{$label} Dispatched";
            $header[] = "{$label} Dispatched (bales)";
            $header[] = "{$label} Returned";
            $header[] = "{$label} Returned (bales)";
            $header[] = "{$label} Sold";
        }
        $header = array_merge($header, [
            'Buyer', 'Buyer Contact', 'Payment Method', 'Amount',
            'M-Pesa Reference', 'Debt Signatory', 'Debt Expected Repayment Date',
            'Trip Total Collected', 'Cash Collected', 'M-Pesa Collected', 'Debt Collected',
            'Expected Revenue', 'Variance', 'Discrepancy', 'Notes',
        ]);

        $escape = fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';
        $rows = [implode(',', array_map($escape, $header))];

        foreach ($trips as $trip) {
            $itemsBySkuId = $trip->items->keyBy('sku_id');
            $recon = $trip->reconciliation;

            $base = [
                $trip->trip_date->toDateString(),
                $trip->status,
                $trip->driver->full_name ?? '',
                $trip->vehicle->reg_no ?? '',
                $trip->route ?? '',
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
                $base[] = $item->qty_carried_bales ?? 0;
                $base[] = $item->qty_returned ?? 0;
                $base[] = $item->qty_returned_bales ?? 0;
                $base[] = $item->qty_sold ?? 0;
            }

            $trailer = [
                $trip->total_collected,
                $trip->cash_collected,
                $trip->mpesa_collected,
                $trip->debt_collected,
                $recon['expected_revenue'],
                $recon['variance'],
                $trip->has_discrepancy ? 'Yes' : 'No',
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
     * Stage 3's downloadable paperwork -- generated the moment Start
     * Trip locks the dispatch, from the same trip record (no duplicate
     * data entry). Signable: Driver + Authorizing Officer name lines.
     */
    public function dispatchSheet(Request $request, $id)
    {
        $trip = DriverTrip::with(self::WITH)->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        if ($trip->status === 'pending_departure') {
            return response()->json(['success' => false, 'message' => 'Dispatch sheet is available once the trip has started'], 422);
        }

        return (new \App\Services\DriverTripSheetService())->dispatchSheet($trip);
    }

    /**
     * Stage 5's downloadable paperwork -- generated the moment End Trip
     * closes the trip.
     */
    public function returnSheet(Request $request, $id)
    {
        $trip = DriverTrip::with(self::WITH)->find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($deny = $this->denyIfNotOwnTrip($request, $trip)) {
            return $deny;
        }
        if ($trip->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Return sheet is available once the trip is completed'], 422);
        }

        return (new \App\Services\DriverTripSheetService())->returnSheet($trip);
    }

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

    // --- Routes reference list -- this is the general delivery-route
    // concept Customers/Orders (Sales) still use, unrelated to Phase 2's
    // free-text trip route below. Untouched by Phase 2.

    public function routes()
    {
        return response()->json(['success' => true, 'data' => RouteModel::orderBy('name')->get()]);
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

    /**
     * Round 3 Phase 2: the trip form's own free-text Route field keeps a
     * typing-convenience autocomplete of the last 20 distinct values
     * actually entered -- purely a suggestion list, never a constraint
     * (unlike the routes() picker above).
     */
    public function recentRoutes()
    {
        $recent = DriverTrip::whereNotNull('route')->where('route', '!=', '')
            ->orderByDesc('created_at')
            ->limit(200) // over-fetch before distinct() -- cheap, avoids a slower DISTINCT+ORDER BY across the whole table
            ->pluck('route')
            ->unique()
            ->take(20)
            ->values();

        return response()->json(['success' => true, 'data' => $recent]);
    }

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

    public function vehicles()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Models\Vehicle::where('active', true)->orderBy('reg_no')->get(['id', 'reg_no']),
        ]);
    }

    public function warehouses()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Models\Warehouse::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Round 3 Phase 2: Authorizing Officer picker for the trip form --
     * Manager/Director-role users only. Driver-accessible (like
     * vehicles()/warehouses() above) since a Driver has to be able to
     * name who authorized their dispatch even though /users itself is
     * Manager/Director-only.
     */
    public function authorizingOfficers()
    {
        return response()->json([
            'success' => true,
            'data' => \App\Models\User::whereHas('role', fn ($q) => $q->whereIn('access_tier', ['manager', 'director']))
                ->where('status', 'active')
                ->orderBy('first_name')
                ->get()
                ->map(fn ($u) => ['id' => $u->id, 'full_name' => $u->full_name]),
        ]);
    }
}

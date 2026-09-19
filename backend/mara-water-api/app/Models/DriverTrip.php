<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DriverTrip extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'trip_date', 'driver_id', 'vehicle_id', 'route', 'warehouse_id', 'location_id',
        'status', 'has_discrepancy', 'locked_at',
        'mileage_start', 'mileage_end', 'fuel_liters', 'fuel_cost',
        'authorizing_officer_id', 'time_out', 'time_in', 'notes',
        // Round 4 Phase 5: the paper-sales reconciliation attestation.
        'reconciliation_confirmed_at', 'reconciliation_confirmed_by',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'mileage_start' => 'integer',
        'mileage_end' => 'integer',
        'fuel_liters' => 'decimal:2',
        'fuel_cost' => 'decimal:2',
        'has_discrepancy' => 'boolean',
        'locked_at' => 'datetime',
        'reconciliation_confirmed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Round 3 Phase 2: the trip-log state machine. pending_departure is
    // fully editable; in_transit locks mileage_start + dispatched
    // quantities (Start Trip already fired, stock already deducted);
    // completed locks everything (End Trip already fired). Director-only
    // unlock() reverses one stage per driver_trip_unlocks below.
    public const STATUSES = ['pending_departure', 'in_transit', 'completed'];

    protected $appends = ['km_covered', 'total_collected', 'reconciliation'];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    // Round 2 Phase 8: the depot the trip loaded stock from and returns
    // unsold stock to -- the same "outlet" concept Log Sale requires.
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    // Round 3 Phase 9: which branch/outlet this trip's sales are
    // attributed to (KDN/KDQ/Warehouse) -- separate from warehouse()
    // above, which is the physical depot stock moved from/to.
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function authorizingOfficer()
    {
        return $this->belongsTo(User::class, 'authorizing_officer_id');
    }

    public function items()
    {
        return $this->hasMany(DriverTripItem::class);
    }

    /**
     * Round 2 Phase 7: replaces the old single cash_collected/
     * mpesa_collected/debt_* trip-level fields -- one row per sale made
     * on the trip, each with its own customer and payment method.
     */
    public function sales()
    {
        return $this->hasMany(DriverTripSale::class);
    }

    // Round 3 Phase 2: Director-only unlock history for this trip.
    public function unlocks()
    {
        return $this->hasMany(DriverTripUnlock::class)->orderByDesc('created_at');
    }

    public function getKmCoveredAttribute(): ?int
    {
        if ($this->mileage_start === null || $this->mileage_end === null) {
            return null;
        }
        return max(0, $this->mileage_end - $this->mileage_start);
    }

    public function getTotalCollectedAttribute(): float
    {
        return round((float) $this->sales->sum('amount'), 2);
    }

    private function collectedByMethod(string $method): float
    {
        return round((float) $this->sales->where('payment_method', $method)->sum('amount'), 2);
    }

    public function getCashCollectedAttribute(): float
    {
        return $this->collectedByMethod('cash');
    }

    public function getMpesaCollectedAttribute(): float
    {
        return $this->collectedByMethod('mpesa');
    }

    public function getDebtCollectedAttribute(): float
    {
        return $this->collectedByMethod('debt');
    }

    /**
     * Round 4 Phase 1/2: reworked around bales as the sole dispatched/
     * sold/returned unit, and around the Sales entity's own line items
     * as the one live source of "Sold" -- there's no more separately-
     * typed qty_sold to compare against. Two checks remain:
     *
     * 1. Stock check, per product/size: sold bales (from
     *    driver_trip_sale_items) should never exceed dispatched bales.
     *    Under the normal flow this can't happen -- addSale() itself
     *    blocks selling past what's still available -- so a mismatch
     *    here only surfaces the edge case Phase 6 exists for: a
     *    Director corrected the dispatched quantity down (via unlock())
     *    after sales had already been logged against the original,
     *    higher number.
     * 2. Money check, trip-wide: the sum of every sale's own line items
     *    (line_total) should equal the sum of every sale logged
     *    (cash + M-Pesa + debt) -- these are the same number by
     *    construction now that a sale's amount is always the computed
     *    sum of its own items (Phase 0), so this is a defensive
     *    self-consistency guard rather than a routine finding.
     */
    public function getReconciliationAttribute(): array
    {
        $saleIds = $this->sales->pluck('id');
        $lineItems = \App\Models\DriverTripSaleItem::whereIn('driver_trip_sale_id', $saleIds)->get();
        $soldBySku = $lineItems->groupBy('sku_id')->map(fn ($rows) => (float) $rows->sum('qty_bales'));

        $unitsSold = 0;
        $stockMismatches = [];

        foreach ($this->items as $item) {
            $sold = (float) ($soldBySku[$item->sku_id] ?? 0);
            $dispatched = (float) $item->qty_carried_bales;
            $unitsSold += $sold;

            if ($sold - $dispatched > 0.001) {
                $stockMismatches[] = [
                    'sku_id' => $item->sku_id,
                    'sku_name' => $item->sku->name ?? 'Product',
                    'dispatched_bales' => $dispatched,
                    'sold_bales' => $sold,
                    'difference' => round($sold - $dispatched, 2),
                ];
            }
        }

        $expectedRevenue = round((float) $lineItems->sum('line_total'), 2);
        $variance = round($this->total_collected - $expectedRevenue, 2);

        return [
            'units_sold' => $unitsSold,
            'expected_revenue' => $expectedRevenue,
            'collected' => $this->total_collected,
            'cash_collected' => $this->cash_collected,
            'mpesa_collected' => $this->mpesa_collected,
            'debt_collected' => $this->debt_collected,
            'variance' => $variance,
            'matches' => abs($variance) < 0.01,
            'stock_mismatches' => $stockMismatches,
            'stock_matches' => empty($stockMismatches),
        ];
    }
}

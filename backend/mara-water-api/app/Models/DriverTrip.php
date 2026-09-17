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
        'trip_date', 'driver_id', 'vehicle_id', 'route_id', 'warehouse_id',
        'mileage_start', 'mileage_end', 'fuel_liters', 'fuel_cost',
        'authorizing_officer_id', 'time_out', 'time_in', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'mileage_start' => 'integer',
        'mileage_end' => 'integer',
        'fuel_liters' => 'decimal:2',
        'fuel_cost' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = ['km_covered', 'total_collected', 'reconciliation'];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    // Round 2 Phase 8: the depot the trip loaded stock from and returns
    // unsold stock to -- the same "outlet" concept Log Sale requires.
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
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
     * Round 2 Phase 7: two independent checks, not one.
     *
     * 1. Stock check, per product/size: what the driver reports selling
     *    (qty_sold) should equal what actually left the vehicle
     *    (qty_carried - qty_returned). A real gap here means something
     *    physical happened -- breakage, a miscount, an unrecorded give-
     *    away -- that money reconciliation alone would never catch,
     *    since it only looks at cash vs. expected revenue.
     * 2. Money check, trip-wide: total qty_sold x unit_price across every
     *    product/size should equal the sum of every sale logged
     *    (cash + M-Pesa + debt). qty_sold is what actually changed hands,
     *    so it's the correct basis for expected revenue now -- not
     *    carried-returned, which Phase 7 keeps around for the stock
     *    check above but no longer uses for money.
     */
    public function getReconciliationAttribute(): array
    {
        $expectedRevenue = 0;
        $unitsSold = 0;
        $stockMismatches = [];

        foreach ($this->items as $item) {
            $impliedSold = max(0, $item->qty_carried - $item->qty_returned);
            $unitsSold += $item->qty_sold;
            $expectedRevenue += $item->qty_sold * (float) $item->unit_price;

            if ($impliedSold !== $item->qty_sold) {
                $stockMismatches[] = [
                    'sku_id' => $item->sku_id,
                    'sku_name' => $item->sku->name ?? 'Product',
                    'dispatched' => $item->qty_carried,
                    'returned' => $item->qty_returned,
                    'implied_sold' => $impliedSold,
                    'reported_sold' => $item->qty_sold,
                    'difference' => $item->qty_sold - $impliedSold,
                ];
            }
        }
        $expectedRevenue = round($expectedRevenue, 2);
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

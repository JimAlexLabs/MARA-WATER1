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
        'trip_date', 'driver_id', 'vehicle_id', 'route_id',
        'mileage_start', 'mileage_end', 'fuel_liters', 'fuel_cost', 'oil_liters',
        'authorizing_officer_id', 'time_out', 'time_in',
        'cash_collected', 'mpesa_collected', 'mpesa_reference', 'notes',
        // Round 2 Phase 6: one optional debt sale per trip -- see
        // DriverTripController::store() and docs on the migration that
        // added these columns.
        'debt_customer_id', 'debt_signatory', 'debt_amount', 'debt_expected_repayment_date', 'debt_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'mileage_start' => 'integer',
        'mileage_end' => 'integer',
        'fuel_liters' => 'decimal:2',
        'fuel_cost' => 'decimal:2',
        'oil_liters' => 'decimal:2',
        'cash_collected' => 'decimal:2',
        'mpesa_collected' => 'decimal:2',
        'debt_amount' => 'decimal:2',
        'debt_expected_repayment_date' => 'date',
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

    public function authorizingOfficer()
    {
        return $this->belongsTo(User::class, 'authorizing_officer_id');
    }

    public function items()
    {
        return $this->hasMany(DriverTripItem::class);
    }

    public function debtCustomer()
    {
        return $this->belongsTo(Customer::class, 'debt_customer_id');
    }

    public function debt()
    {
        return $this->belongsTo(Debt::class, 'debt_id');
    }

    public function getKmCoveredAttribute(): ?int
    {
        if ($this->mileage_start === null || $this->mileage_end === null) {
            return null;
        }
        return max(0, $this->mileage_end - $this->mileage_start);
    }

    /**
     * Round 2 Phase 6: a debt sale is still money accounted for -- just
     * not collected yet -- so it belongs in "collected" for
     * reconciliation purposes the same way cash/M-Pesa do. Before this
     * phase, any credit given on a trip had nowhere to go and would show
     * up as an unexplained variance even though nothing was actually
     * wrong.
     */
    public function getTotalCollectedAttribute(): float
    {
        return round((float) $this->cash_collected + (float) $this->mpesa_collected + (float) $this->debt_amount, 2);
    }

    /**
     * Stock carried minus returned should equal stock sold, which should
     * reconcile against cash + M-Pesa collected. Flag mismatches rather
     * than silently accepting them -- that's the whole point of this
     * table existing instead of a paper worksheet.
     */
    public function getReconciliationAttribute(): array
    {
        $expectedRevenue = 0;
        $unitsSold = 0;
        foreach ($this->items as $item) {
            $sold = max(0, $item->qty_carried - $item->qty_returned);
            $unitsSold += $sold;
            $expectedRevenue += $sold * (float) $item->unit_price;
        }
        $expectedRevenue = round($expectedRevenue, 2);
        $variance = round($this->total_collected - $expectedRevenue, 2);

        return [
            'units_sold' => $unitsSold,
            'expected_revenue' => $expectedRevenue,
            'collected' => $this->total_collected,
            'debt_amount' => (float) $this->debt_amount,
            'variance' => $variance,
            'matches' => abs($variance) < 0.01,
        ];
    }
}

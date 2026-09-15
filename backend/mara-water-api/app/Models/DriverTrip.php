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

    public function getKmCoveredAttribute(): ?int
    {
        if ($this->mileage_start === null || $this->mileage_end === null) {
            return null;
        }
        return max(0, $this->mileage_end - $this->mileage_start);
    }

    public function getTotalCollectedAttribute(): float
    {
        return round((float) $this->cash_collected + (float) $this->mpesa_collected, 2);
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
            'variance' => $variance,
            'matches' => abs($variance) < 0.01,
        ];
    }
}

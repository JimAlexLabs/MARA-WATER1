<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Round 4 Phase 6: one row per flagged discrepancy -- cash (revenue
 * self-consistency), stock (oversold vs. dispatched after a Director
 * correction), or mileage (Phase 7's outlier-distance flag). Never
 * surfaced to the Driver dashboard (Phase 6 point 1) -- Manager/Director
 * only, via the dedicated Discrepancies page and their notification bell.
 */
class Discrepancy extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'driver_trip_id', 'vehicle_id', 'driver_id', 'date', 'category',
        'amount', 'description', 'status', 'resolution_note',
        'resolved_by', 'resolved_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(DriverTrip::class, 'driver_trip_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

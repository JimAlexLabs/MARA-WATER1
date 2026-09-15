<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Vehicle extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'reg_no',
        'make',
        'model',
        'year',
        'capacity',
        'active',
        'fuel_type',
        'notes',
        'insurance_expiry',
        'inspection_expiry',
        'speed_gov_status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'capacity' => 'integer',
        'active' => 'boolean',
        'insurance_expiry' => 'date',
        'inspection_expiry' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // `driver` is a derived accessor (see getDriverAttribute below), not a
    // real relation or column -- append it so it still serializes the way
    // API consumers already expect (vehicle.driver.first_name etc.).
    protected $appends = ['driver'];

    public function manifests()
    {
        return $this->hasMany(Manifest::class);
    }

    public function driverAssignments()
    {
        return $this->hasMany(DriverAssignment::class);
    }

    /**
     * The currently-active (no end_date) driver assignment, if any.
     * There's no driver_id column on vehicles -- a driver<->vehicle link
     * is a time-bounded driver_assignments row, which also gives us
     * reassignment history for free.
     */
    public function currentAssignment()
    {
        return $this->hasOne(DriverAssignment::class)->whereNull('end_date')->latestOfMany('start_date');
    }

    /**
     * Kept as `driver` (not `currentAssignment->driver`) so existing API
     * consumers that read vehicle.driver.first_name etc. keep working.
     * Eager-load 'currentAssignment.driver' to avoid an N+1 here.
     */
    public function getDriverAttribute()
    {
        return $this->currentAssignment?->driver;
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

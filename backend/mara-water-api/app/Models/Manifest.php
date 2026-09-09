<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Manifest extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'manifest_no',
        'vehicle_id',
        'driver_id',
        'sales_officer_id',
        'warehouse_id',
        'odometer_out',
        'odometer_in',
        'fuel_liters',
        'start_at',
        'end_at',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'odometer_out' => 'integer',
        'odometer_in' => 'integer',
        'fuel_liters' => 'decimal:2',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function salesOfficer()
    {
        return $this->belongsTo(User::class, 'sales_officer_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}

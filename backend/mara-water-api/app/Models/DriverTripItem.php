<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DriverTripItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'driver_trip_id', 'sku_id', 'qty_carried', 'qty_returned', 'unit_price',
    ];

    protected $casts = [
        'qty_carried' => 'integer',
        'qty_returned' => 'integer',
        'unit_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getQtySoldAttribute(): int
    {
        return max(0, $this->qty_carried - $this->qty_returned);
    }

    public function trip()
    {
        return $this->belongsTo(DriverTrip::class, 'driver_trip_id');
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
}

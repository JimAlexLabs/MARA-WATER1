<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DriverTripSaleItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'driver_trip_sale_id', 'sku_id', 'qty_bales', 'unit_price', 'line_total',
    ];

    protected $casts = [
        'qty_bales' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sale()
    {
        return $this->belongsTo(DriverTripSale::class, 'driver_trip_sale_id');
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
}

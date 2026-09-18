<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class DriverTripItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'driver_trip_id', 'sku_id', 'qty_carried', 'qty_returned', 'qty_sold', 'unit_price',
        'qty_carried_bales', 'qty_returned_bales',
    ];

    protected $casts = [
        'qty_carried' => 'integer',
        'qty_returned' => 'integer',
        // Round 3 Phase 2: bales are a separate, parallel unit -- entered
        // and reconciled on their own, with no conversion to/from the
        // bottle-level qty_carried/qty_returned/qty_sold above (those
        // keep driving stock deduction and revenue exactly as before).
        'qty_carried_bales' => 'integer',
        'qty_returned_bales' => 'integer',
        // Round 2 Phase 7: qty_sold is a real, independently-reported
        // column now (dispatched/returned/sold are three separate
        // numbers per the spec, precisely so a mismatch between
        // carried-returned and what the driver reports selling can be
        // caught -- see DriverTrip::getReconciliationAttribute()). There
        // used to be a getQtySoldAttribute() accessor here computing
        // carried-returned under this same name; it would have silently
        // shadowed this real column on every read (the exact class of
        // bug Phase 9's net_qty accessor collision was), so it's gone.
        'qty_sold' => 'integer',
        'unit_price' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(DriverTrip::class, 'driver_trip_id');
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
}

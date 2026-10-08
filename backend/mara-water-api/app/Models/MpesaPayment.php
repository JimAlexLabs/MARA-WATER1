<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Round 6: MARA's own ledger of what it asked the shared AfriGig
 * payment gateway to do, and what the gateway reported back. See the
 * migration docblock for why sale_id is two nullable FKs instead of one
 * polymorphic column.
 */
class MpesaPayment extends Model
{
    use HasUuids;

    protected $table = 'mpesa_payments';

    protected $fillable = [
        'channel', 'driver_trip_sale_id', 'order_id', 'trip_id', 'location_id',
        'reference', 'gateway_payment_id', 'checkout_request_id', 'merchant_request_id',
        'phone', 'amount', 'status', 'result_code', 'result_desc', 'mpesa_receipt',
        'initiated_by', 'idempotency_key', 'pending_sale_snapshot',
        'unmatched', 'assigned_sale_id', 'assigned_sale_type', 'assigned_by', 'assigned_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'unmatched' => 'boolean',
        'pending_sale_snapshot' => 'array',
        'assigned_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function driverTripSale()
    {
        return $this->belongsTo(DriverTripSale::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function trip()
    {
        return $this->belongsTo(DriverTrip::class, 'trip_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['success', 'failed', 'cancelled', 'timeout'], true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MpesaPayment extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id', 'driver_trip_sale_id', 'reference', 'gateway_payment_id',
        'merchant_request_id', 'checkout_request_id', 'phone', 'amount', 'status',
        'result_code', 'result_desc', 'mpesa_receipt', 'initiated_by', 'channel',
        'trip_id', 'location', 'idempotency_key', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function tripSale()
    {
        return $this->belongsTo(DriverTripSale::class, 'driver_trip_sale_id');
    }
}

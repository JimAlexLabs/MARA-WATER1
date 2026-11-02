<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaybillWatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id', 'phone', 'amount', 'trip_id', 'order_id',
        'driver_trip_sale_id', 'initiated_by', 'channel', 'status',
        'expires_at', 'matched_payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open')->where('expires_at', '>', now());
    }
}

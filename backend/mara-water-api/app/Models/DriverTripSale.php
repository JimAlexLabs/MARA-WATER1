<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Round 2 Phase 7: one row per sale made on a driver trip (one per
 * customer/stop), replacing the old single trip-level cash_collected/
 * mpesa_collected/debt_* totals -- "traceable to a real buyer, not just
 * a total".
 */
class DriverTripSale extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'driver_trip_id', 'customer_id', 'payment_method', 'amount',
        'mpesa_reference', 'debt_signatory', 'debt_expected_repayment_date', 'debt_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'debt_expected_repayment_date' => 'date',
    ];

    public function trip()
    {
        return $this->belongsTo(DriverTrip::class, 'driver_trip_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function debt()
    {
        return $this->belongsTo(Debt::class);
    }
}

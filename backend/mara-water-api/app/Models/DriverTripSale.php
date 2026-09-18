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
        // Round 3 Phase 3: mpesa_reference is dual-purpose -- also holds
        // the QR/reference code for payment_method='pay_direct', rather
        // than a separate column for what's the same "a reference string
        // for a non-cash, non-debt payment" concept.
        'mpesa_reference', 'debt_signatory', 'debt_expected_repayment_date', 'debt_id',
        'physical_receipt_no', 'physical_delivery_note_no',
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

    // Round 3 Phase 2/3: optional per-brand/size breakdown of this sale
    // (bales, driver-entered price) -- feeds stage 4's running tally.
    // `amount` above stays the authoritative total for cash/M-Pesa/debt
    // reconciliation regardless of whether items were itemized.
    public function items()
    {
        return $this->hasMany(DriverTripSaleItem::class);
    }
}

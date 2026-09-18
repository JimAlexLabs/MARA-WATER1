<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Order extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'order_no',
        'customer_id',
        'route_id',
        'warehouse_id',
        'location_id',
        'sales_officer_id',
        'status',
        'order_date',
        'requested_date',
        'price_list_id',
        'total_amount',
        'payment_method',
        'payment_reference',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'requested_date' => 'date',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function salesOfficer()
    {
        return $this->belongsTo(User::class, 'sales_officer_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    // Round 3 Phase 9: which branch/outlet this order is attributed to
    // (KDN/KDQ/Warehouse) -- separate from warehouse() above.
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Round 2 Phase 9: an order created via store() (the draft ->
     * confirmed -> dispatched -> delivered workflow) never reaches the
     * frontend today and never gets a payment_method -- so
     * payment_method IS NOT NULL is exactly "created via logSale(), a
     * real committed sale" for now, distinguishing it from a stray draft
     * that never became a transaction. Analytics/reporting on revenue
     * should use this, not every row in the table.
     */
    public function scopeCompletedSale($query)
    {
        return $query->whereNotNull('payment_method');
    }
}

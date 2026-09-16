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
}

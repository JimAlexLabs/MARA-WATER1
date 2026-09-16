<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OrderItem extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $fillable = [
        'order_id',
        'sku_id',
        'qty',
        'qty_returned',
        'unit_price',
        'unit_price_overridden',
        'override_reason',
        'discount',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty' => 'integer',
        'qty_returned' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_price_overridden' => 'boolean',
        'discount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = ['net_qty', 'line_total'];

    /**
     * What actually stayed sold once returns are netted out.
     */
    public function getNetQtyAttribute()
    {
        return $this->qty - $this->qty_returned;
    }

    public function getLineTotalAttribute()
    {
        return ($this->net_qty * $this->unit_price) - $this->discount;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FinishedGoodsBackorder extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id', 'order_item_id', 'sku_id', 'warehouse_id',
        'qty_reserved', 'qty_fulfilled', 'status', 'created_by',
    ];

    protected $casts = [
        'qty_reserved' => 'decimal:2',
        'qty_fulfilled' => 'decimal:2',
    ];

    public function remaining(): float
    {
        return max(0, (float) $this->qty_reserved - (float) $this->qty_fulfilled);
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PriceListItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'price_list_id',
        'sku_id',
        'unit_price',
        'currency',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'deleted_at' => 'datetime',
    ];

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }
}

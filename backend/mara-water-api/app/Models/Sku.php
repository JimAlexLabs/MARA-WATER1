<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Sku extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'name',
        'brand',
        'size_liters',
        'unit',
        'expiry_days',
        'active',
        'reorder_threshold',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'size_liters' => 'decimal:2',
        'expiry_days' => 'integer',
        'active' => 'boolean',
        'reorder_threshold' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function batches()
    {
        return $this->hasMany(Batch::class);
    }

    public function bomItems()
    {
        return $this->hasMany(BomItem::class);
    }

    public function stockItems()
    {
        return $this->hasMany(StockItem::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class StockItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'item_type',
        'material_id',
        'sku_id',
        'batch_id',
        'warehouse_id',
        'qty',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the material that owns the stock item
     */
    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Get the SKU that owns the stock item
     */
    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * Get the batch that owns the stock item
     */
    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Get the warehouse that owns the stock item
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the user who created the stock item
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the stock item
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

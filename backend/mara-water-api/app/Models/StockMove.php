<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class StockMove extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'move_type',
        'item_type',
        'material_id',
        'sku_id',
        'batch_id',
        'warehouse_from_id',
        'warehouse_to_id',
        'qty',
        'uom',
        'unit_cost',
        'ref_entity',
        'ref_id',
        'moved_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the material that owns the stock move
     */
    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Get the SKU that owns the stock move
     */
    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * Get the batch that owns the stock move
     */
    public function batch()
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Get the warehouse from which the stock was moved
     */
    public function warehouseFrom()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_from_id');
    }

    /**
     * Get the warehouse to which the stock was moved
     */
    public function warehouseTo()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_to_id');
    }

    /**
     * Get the user who moved the stock
     */
    public function movedBy()
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    /**
     * Get the user who created the stock move
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the stock move
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

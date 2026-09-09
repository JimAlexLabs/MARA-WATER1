<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Material extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'category',
        'uom',
        'is_consumable',
        'min_level',
        'lead_time_days',
        'supplier_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_consumable' => 'boolean',
        'min_level' => 'decimal:3',
        'lead_time_days' => 'integer',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the supplier that owns the material
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the stock items for this material
     */
    public function stockItems()
    {
        return $this->hasMany(StockItem::class);
    }

    /**
     * Get the stock moves for this material
     */
    public function stockMoves()
    {
        return $this->hasMany(StockMove::class);
    }

    /**
     * Get the user who created the material
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the material
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

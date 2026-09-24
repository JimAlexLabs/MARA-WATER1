<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MaterialBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'material_id',
        'batch_number',
        'purchase_date',
        'supplier_name',
        'supplier_code',
        'unit_cost',
        'transport_cost',
        'qty_received',
        'qty_remaining',
        'package_unit',
        'warehouse_id',
        'received_by',
        'notes',
        'quality_status',
        'quality_checked_at',
        'quality_checked_by',
        'quality_notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'unit_cost' => 'decimal:4',
        'qty_received' => 'decimal:3',
        'qty_remaining' => 'decimal:3',
        'quality_checked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function qualityCheckedBy()
    {
        return $this->belongsTo(User::class, 'quality_checked_by');
    }

    public function consumptions()
    {
        return $this->hasMany(MaterialBatchConsumption::class);
    }

    public function scopePassedQuality($query)
    {
        return $query->where('quality_status', 'passed');
    }

    public function scopeAvailable($query)
    {
        return $query->passedQuality()->where('qty_remaining', '>', 0);
    }
}

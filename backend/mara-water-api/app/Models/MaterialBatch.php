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
        'unit_cost',
        'qty_received',
        'warehouse_id',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'unit_cost' => 'decimal:4',
        'qty_received' => 'decimal:3',
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
}

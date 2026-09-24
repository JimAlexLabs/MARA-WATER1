<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MaterialBatchConsumption extends Model
{
    use HasUuids;

    protected $fillable = [
        'material_batch_id',
        'packaging_run_id',
        'material_id',
        'qty_consumed',
        'uom',
    ];

    protected $casts = [
        'qty_consumed' => 'decimal:3',
    ];

    public function materialBatch()
    {
        return $this->belongsTo(MaterialBatch::class);
    }

    public function packagingRun()
    {
        return $this->belongsTo(PackagingRun::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}

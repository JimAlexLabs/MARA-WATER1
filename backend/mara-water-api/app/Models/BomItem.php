<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Bill of Materials line: how much of one raw material one finished bale
 * of a SKU consumes. Production logging (PackagingRunController) multiplies
 * qty_per_unit × bales produced (good_qty) to auto-deduct packaging.
 */
class BomItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'bom_items';

    protected $fillable = [
        'sku_id',
        'material_id',
        'qty_per_unit',
        'uom',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty_per_unit' => 'decimal:4',
        'deleted_at' => 'datetime',
    ];

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}

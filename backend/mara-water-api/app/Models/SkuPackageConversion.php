<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-supplier bag→bale conversion for empty bottles.
 * Bags arrive from FineLine / Blowplast; bales are the sales unit after production.
 */
class SkuPackageConversion extends Model
{
    use HasUuids;

    protected $fillable = [
        'supplier_code',
        'sku_id',
        'bottles_per_bag',
        'bottles_per_bale',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'bottles_per_bag' => 'decimal:3',
        'bottles_per_bale' => 'decimal:3',
    ];

    public function sku()
    {
        return $this->belongsTo(Sku::class);
    }

    /** Bags → bales (how many bales one bag yields for this SKU). */
    public function bagsToBales(float $bags): float
    {
        $perBag = (float) $this->bottles_per_bag;
        $perBale = (float) $this->bottles_per_bale;
        if ($perBag <= 0 || $perBale <= 0) {
            return 0;
        }
        return round(($bags * $perBag) / $perBale, 3);
    }
}

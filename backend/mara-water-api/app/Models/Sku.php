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

    /**
     * Finished-goods production/sales unit is the bale.
     * Returns how many bottles (or containers) make one bale for this SKU size.
     */
    public function bottlesPerBale(): int
    {
        $size = round((float) $this->size_liters, 2);
        $map = config('mara_operations.bottles_per_bale_by_size', []);
        foreach ($map as $key => $bpb) {
            if (abs((float) $key - $size) < 0.011) {
                return max(1, (int) $bpb);
            }
        }

        return (int) config('mara_operations.bottles_per_bale', 24);
    }

    public function usesBailingPaper(): bool
    {
        $size = round((float) $this->size_liters, 2);

        return in_array($size, [0.5, 1.0, 1.5, 5.0], true);
    }

    /** Canonical size key used in material codes, e.g. 0.5 → "0.5L". */
    public function sizeCode(): string
    {
        $size = round((float) $this->size_liters, 2);
        if (abs($size - (int) $size) < 0.011) {
            return ((int) $size) . 'L';
        }

        return rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.') . 'L';
    }

    /**
     * Customer-owned jerrican fill (5L / 10L / 20L only).
     * Not MARA finished goods — sales log revenue only; no unsold stock,
     * warehouse deduct, or production material draw.
     */
    public function isRefill(): bool
    {
        $brand = strtoupper(trim((string) $this->brand));
        $code = strtoupper(trim((string) $this->code));
        if ($brand !== 'REFILL' && ! str_starts_with($code, 'REFILL')) {
            return false;
        }
        $size = round((float) $this->size_liters, 2);

        return in_array($size, [5.0, 10.0, 20.0], true);
    }
}

<?php

namespace App\Services;

use App\Models\Sku;

/**
 * Some sellable SKUs draw down a different physical stock SKU.
 * Premium Custom 0.5L sales deduct Premium 0.5L bottle stock.
 */
class SkuStockAliasService
{
    /** sellable code => physical stock code */
    public const ALIASES = [
        'PREM-CUSTOM-0.5L' => 'PREM-0.5L',
    ];

    public static function stockSkuIdFor(?string $sellableSkuId): ?string
    {
        if (! $sellableSkuId) {
            return null;
        }

        $sku = Sku::find($sellableSkuId);
        if (! $sku) {
            return $sellableSkuId;
        }

        $stockCode = self::ALIASES[$sku->code] ?? null;
        if (! $stockCode) {
            return $sellableSkuId;
        }

        $stock = Sku::where('code', $stockCode)->whereNull('deleted_at')->first();

        return $stock?->id ?: $sellableSkuId;
    }

    public static function stockSkuFor(?Sku $sellable): ?Sku
    {
        if (! $sellable) {
            return null;
        }

        $stockCode = self::ALIASES[$sellable->code] ?? null;
        if (! $stockCode) {
            return $sellable;
        }

        return Sku::where('code', $stockCode)->whereNull('deleted_at')->first() ?: $sellable;
    }
}

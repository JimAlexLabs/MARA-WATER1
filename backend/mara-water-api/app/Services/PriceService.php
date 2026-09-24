<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;

/**
 * Round 5B Phase 4: stable read API for selling prices.
 * Sales Management (Claude Code Round 5A) and trip flows should call
 * getCurrentPrice() / resolveForSku() rather than hardcoding amounts.
 */
class PriceService
{
    public function defaultList(): ?PriceList
    {
        return PriceList::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('list_kind', 'default')->orWhere('is_default', true);
            })
            ->orderByDesc('is_default')
            ->orderByDesc('list_kind')
            ->first();
    }

    public function corporateList(): ?PriceList
    {
        return PriceList::whereNull('deleted_at')
            ->where('list_kind', 'corporate')
            ->orderBy('name')
            ->first();
    }

    public function listByKind(string $kind): ?PriceList
    {
        return $kind === 'corporate' ? $this->corporateList() : $this->defaultList();
    }

    /**
     * Resolve unit price for a brand + size (liters). Prefers exact
     * active SKU match; falls back to size-only within brand.
     */
    public function getCurrentPrice(?string $brand, $sizeLiters, string $kind = 'default'): ?float
    {
        $list = $this->listByKind($kind);
        if (!$list) {
            return null;
        }

        $skuQuery = Sku::where('active', true)->whereNull('deleted_at');
        if ($brand !== null && $brand !== '') {
            $skuQuery->where('brand', $brand);
        }
        if ($sizeLiters !== null && $sizeLiters !== '') {
            $skuQuery->where('size_liters', $sizeLiters);
        }
        $sku = $skuQuery->orderBy('name')->first();
        if (!$sku) {
            return null;
        }

        return $this->resolveForSku($sku->id, $kind);
    }

    public function resolveForSku(string $skuId, string $kind = 'default'): ?float
    {
        $list = $this->listByKind($kind);
        if (!$list) {
            return null;
        }

        $price = PriceListItem::where('price_list_id', $list->id)
            ->where('sku_id', $skuId)
            ->whereNull('deleted_at')
            ->value('unit_price');

        return $price !== null ? (float) $price : null;
    }

    /**
     * Map of sku_id => unit_price for the given kind (default retail).
     */
    public function priceMap(string $kind = 'default'): array
    {
        $list = $this->listByKind($kind);
        if (!$list) {
            return [];
        }

        return PriceListItem::where('price_list_id', $list->id)
            ->whereNull('deleted_at')
            ->pluck('unit_price', 'sku_id')
            ->map(fn ($p) => (float) $p)
            ->all();
    }
}

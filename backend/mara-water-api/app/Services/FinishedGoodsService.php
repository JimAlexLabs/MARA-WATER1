<?php

namespace App\Services;

use App\Models\DriverTripItem;
use App\Models\OrderItem;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Finished bales are the only thing that can be sold or loaded on a truck.
 * Unfinished bottles, labels, caps, seals and bailing paper stay on the
 * capacity screen and are consumed when a batch is recorded — never again
 * at sale time.
 *
 * On-hand is warehouse SKU stock (posted by production). Open trip loads
 * and unpaid M-Pesa sales are reserved so two people cannot sell the same bales.
 */
class FinishedGoodsService
{
    public function warehouseId(?string $warehouseId = null): ?string
    {
        if ($warehouseId) {
            return $warehouseId;
        }

        return Warehouse::whereNull('deleted_at')
            ->whereIn('code', ['MAIN', 'WH-MAIN'])
            ->orderBy('code')
            ->value('id')
            ?: Warehouse::whereNull('deleted_at')->orderBy('name')->value('id');
    }

    /** Physical stock SKU plus every sellable SKU that draws it (custom → premium). */
    public function familyIds(string $skuId): array
    {
        $stockId = SkuStockAliasService::stockSkuIdFor($skuId) ?: $skuId;
        $ids = [$stockId, $skuId];
        $stock = Sku::find($stockId);
        if ($stock) {
            foreach (SkuStockAliasService::ALIASES as $sellCode => $stockCode) {
                if ($stock->code === $stockCode) {
                    $sell = Sku::where('code', $sellCode)->whereNull('deleted_at')->value('id');
                    if ($sell) {
                        $ids[] = $sell;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function onHandBales(string $skuId, ?string $warehouseId = null): float
    {
        $stockId = SkuStockAliasService::stockSkuIdFor($skuId) ?: $skuId;
        $warehouseId = $this->warehouseId($warehouseId);
        if (! $warehouseId) {
            return 0.0;
        }

        return (float) StockItem::where('item_type', 'sku')
            ->where('sku_id', $stockId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->sum('qty');
    }

    public function reservedBales(string $skuId, ?string $warehouseId = null, ?string $exceptTripId = null): float
    {
        $ids = $this->familyIds($skuId);

        $trips = DriverTripItem::query()
            ->whereIn('sku_id', $ids)
            ->whereHas('trip', function ($q) use ($exceptTripId) {
                $q->whereNull('deleted_at')->whereIn('status', ['pending_departure', 'in_transit']);
                if ($exceptTripId) {
                    $q->where('id', '!=', $exceptTripId);
                }
            })
            ->sum('qty_carried_bales');

        $awaiting = OrderItem::query()
            ->whereIn('order_items.sku_id', $ids)
            ->whereNull('order_items.deleted_at')
            ->whereHas('order', function ($q) {
                $q->whereNull('deleted_at')
                    ->where('stock_posted', false)
                    ->where('status', 'awaiting_payment');
            })
            ->sum(DB::raw('GREATEST(order_items.qty - COALESCE(order_items.qty_returned, 0), 0)'));

        return (float) $trips + (float) $awaiting;
    }

    public function availableBales(string $skuId, ?string $warehouseId = null, ?string $exceptTripId = null): float
    {
        return $this->onHandBales($skuId, $warehouseId) - $this->reservedBales($skuId, $warehouseId, $exceptTripId);
    }

    public function assertCanTake(string $skuId, float $bales, ?string $warehouseId = null, ?string $exceptTripId = null): void
    {
        if ($bales <= 0) {
            return;
        }
        $available = $this->availableBales($skuId, $warehouseId, $exceptTripId);
        if ($bales > $available + 0.001) {
            $sku = Sku::find($skuId);
            $name = $sku?->name ?: 'that product';
            $shown = max(0, round($available, 2));
            throw new \InvalidArgumentException(
                "Only {$shown} bales of {$name} have been produced and are still unsold. This needs {$bales}."
            );
        }
    }

    /**
     * @param  list<array{sku_id?:string, qty_carried_bales?:numeric}>  $items
     */
    public function assertLoads(array $items, ?string $warehouseId = null, ?string $exceptTripId = null): void
    {
        $need = [];
        foreach ($items as $item) {
            $skuId = $item['sku_id'] ?? null;
            $bales = (float) ($item['qty_carried_bales'] ?? 0);
            if (! $skuId || $bales <= 0) {
                continue;
            }
            $need[$skuId] = ($need[$skuId] ?? 0) + $bales;
        }
        foreach ($need as $skuId => $bales) {
            $this->assertCanTake($skuId, $bales, $warehouseId, $exceptTripId);
        }
    }

    /** Current unsold finished bales per stock SKU (on hand minus reservations). */
    public function balances(?string $warehouseId = null): array
    {
        $warehouseId = $this->warehouseId($warehouseId);
        if (! $warehouseId) {
            return [];
        }

        $rows = StockItem::query()
            ->where('item_type', 'sku')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->selectRaw('sku_id, SUM(qty) as qty')
            ->groupBy('sku_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $sku = Sku::find($row->sku_id);
            if (! $sku) {
                continue;
            }
            $onHand = (float) $row->qty;
            $reserved = $this->reservedBales($sku->id, $warehouseId);
            $out[] = [
                'sku_id' => $sku->id,
                'sku_code' => $sku->code,
                'sku_name' => $sku->name,
                'brand' => $sku->brand,
                'on_hand_bales' => round($onHand, 2),
                'reserved_bales' => round($reserved, 2),
                'unsold_bales' => round($onHand - $reserved, 2),
            ];
        }

        return $out;
    }

    public function unsoldFor(string $skuId, ?string $warehouseId = null): float
    {
        return round($this->availableBales($skuId, $warehouseId), 2);
    }
}

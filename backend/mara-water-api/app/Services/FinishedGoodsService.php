<?php

namespace App\Services;

use App\Models\DriverTripItem;
use App\Models\OrderItem;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\StockMove;
use Illuminate\Support\Facades\DB;

/**
 * Finished bales are the only thing that can be sold or loaded on a truck.
 * Unfinished bottles, labels, caps, seals and bailing paper stay on the
 * capacity screen and are consumed when a batch is recorded — never again
 * at sale time.
 *
 * On-hand is warehouse SKU stock (posted by production), summed across the
 * plant's main warehouse ids so MAIN vs WH-MAIN never hides real stock.
 * Open trip loads, unpaid M-Pesa sales, and production backorders reserve
 * bales so two people cannot sell the same ones.
 */
class FinishedGoodsService
{
    public function warehouseId(?string $warehouseId = null): ?string
    {
        return MainWarehouse::id($warehouseId);
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
        $warehouseIds = $warehouseId
            ? array_values(array_unique(array_filter([$warehouseId, ...MainWarehouse::ids()])))
            : MainWarehouse::ids();
        if ($warehouseIds === []) {
            $fallback = $this->warehouseId();
            if (! $fallback) {
                return 0.0;
            }
            $warehouseIds = [$fallback];
        }

        $fromItems = (float) StockItem::where('item_type', 'sku')
            ->where('sku_id', $stockId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNull('deleted_at')
            ->sum('qty');

        // If stock_items drifted (legacy posts / warehouse split), rebuild from moves.
        if (abs($fromItems) < 0.001) {
            $fromMoves = $this->onHandFromMoves($stockId, $warehouseIds);
            if ($fromMoves > 0.001) {
                return $fromMoves;
            }
        }

        return $fromItems;
    }

    /** Net finished bales from stock_moves when stock_items is empty. */
    private function onHandFromMoves(string $stockSkuId, array $warehouseIds): float
    {
        $in = (float) StockMove::query()
            ->whereNull('deleted_at')
            ->where('item_type', 'sku')
            ->where('sku_id', $stockSkuId)
            ->whereIn('move_type', ['produce', 'return', 'grn'])
            ->where(function ($q) use ($warehouseIds) {
                $q->whereIn('warehouse_to_id', $warehouseIds);
            })
            ->sum('qty');

        $out = (float) StockMove::query()
            ->whereNull('deleted_at')
            ->where('item_type', 'sku')
            ->where('sku_id', $stockSkuId)
            ->whereIn('move_type', ['issue', 'dispatch'])
            ->where(function ($q) use ($warehouseIds) {
                $q->whereIn('warehouse_from_id', $warehouseIds);
            })
            ->sum('qty');

        // Adjusts: into warehouse_to adds, from warehouse_from removes.
        $adjustIn = (float) StockMove::query()
            ->whereNull('deleted_at')
            ->where('item_type', 'sku')
            ->where('sku_id', $stockSkuId)
            ->where('move_type', 'adjust')
            ->whereIn('warehouse_to_id', $warehouseIds)
            ->sum('qty');
        $adjustOut = (float) StockMove::query()
            ->whereNull('deleted_at')
            ->where('item_type', 'sku')
            ->where('sku_id', $stockSkuId)
            ->where('move_type', 'adjust')
            ->whereIn('warehouse_from_id', $warehouseIds)
            ->sum('qty');

        return max(0, $in + $adjustIn - $out - $adjustOut);
    }

    public function reservedBales(
        string $skuId,
        ?string $warehouseId = null,
        ?string $exceptTripId = null,
        ?string $exceptOrderId = null
    ): float {
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
            ->whereHas('order', function ($q) use ($exceptOrderId) {
                $q->whereNull('deleted_at')
                    ->where('stock_posted', false)
                    ->where('status', 'awaiting_payment');
                if ($exceptOrderId) {
                    $q->where('id', '!=', $exceptOrderId);
                }
            })
            ->sum(DB::raw('GREATEST(order_items.qty - COALESCE(order_items.qty_returned, 0), 0)'));

        $backorders = app(FinishedGoodsBackorderService::class)->openReserved($skuId);

        return (float) $trips + (float) $awaiting + (float) $backorders;
    }

    public function availableBales(
        string $skuId,
        ?string $warehouseId = null,
        ?string $exceptTripId = null,
        ?string $exceptOrderId = null
    ): float {
        return $this->onHandBales($skuId, $warehouseId)
            - $this->reservedBales($skuId, $warehouseId, $exceptTripId, $exceptOrderId);
    }

    /**
     * Split a sale into bales taken from on-hand stock vs reserved against
     * the next production. Refills never touch finished goods.
     *
     * @return array{from_stock: float, backorder: float, available: float}
     */
    public function allocateSale(
        string $skuId,
        float $bales,
        ?string $warehouseId = null,
        ?string $exceptOrderId = null
    ): array {
        $sku = Sku::find($skuId);
        if ($bales <= 0 || ($sku && $sku->isRefill())) {
            return ['from_stock' => 0.0, 'backorder' => 0.0, 'available' => 0.0];
        }
        $available = max(0, $this->availableBales($skuId, $warehouseId, null, $exceptOrderId));
        $fromStock = min($bales, $available);
        $backorder = max(0, $bales - $fromStock);

        return [
            'from_stock' => round($fromStock, 2),
            'backorder' => round($backorder, 2),
            'available' => round($available, 2),
        ];
    }

    public function assertCanTake(string $skuId, float $bales, ?string $warehouseId = null, ?string $exceptTripId = null): void
    {
        if ($bales <= 0) {
            return;
        }
        $sku = Sku::find($skuId);
        if ($sku && $sku->isRefill()) {
            return;
        }
        $available = $this->availableBales($skuId, $warehouseId, $exceptTripId);
        if ($bales > $available + 0.001) {
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
            $sku = Sku::find($skuId);
            if ($sku && $sku->isRefill()) {
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
        $warehouseIds = $warehouseId ? [$warehouseId] : MainWarehouse::ids();
        if ($warehouseIds === []) {
            return [];
        }

        $rows = StockItem::query()
            ->where('item_type', 'sku')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNull('deleted_at')
            ->selectRaw('sku_id, SUM(qty) as qty')
            ->groupBy('sku_id')
            ->get();

        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $sku = Sku::find($row->sku_id);
            if (! $sku || $sku->isRefill()) {
                continue;
            }
            $seen[$sku->id] = true;
            $onHand = $this->onHandBales($sku->id, $warehouseId);
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

        // Include SKUs that only appear via moves fallback (empty stock_items).
        foreach (Sku::query()->where(function ($q) {
            $q->where('active', true)->orWhereNull('active');
        })->get() as $sku) {
            if (isset($seen[$sku->id]) || $sku->isRefill()) {
                continue;
            }
            $onHand = $this->onHandBales($sku->id, $warehouseId);
            if ($onHand <= 0.001) {
                continue;
            }
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
        $sku = Sku::find($skuId);
        if ($sku && $sku->isRefill()) {
            return 0.0;
        }

        return round($this->availableBales($skuId, $warehouseId), 2);
    }
}

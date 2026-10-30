<?php

namespace App\Services;

use App\Http\Controllers\Api\InventoryController;
use App\Models\FinishedGoodsBackorder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sku;
use Illuminate\Support\Facades\Auth;

/**
 * Walk-in / outlet sales may be logged before the bales exist.
 * Open backorders reserve the next produced finished goods and are
 * deducted automatically when a production batch lands.
 */
class FinishedGoodsBackorderService
{
    public function openReserved(string $skuId): float
    {
        $ids = app(FinishedGoodsService::class)->familyIds($skuId);

        return (float) FinishedGoodsBackorder::query()
            ->whereIn('sku_id', $ids)
            ->where('status', 'open')
            ->selectRaw('COALESCE(SUM(GREATEST(qty_reserved - qty_fulfilled, 0)), 0) as remaining')
            ->value('remaining');
    }

    /**
     * Reserve shortfall against future production. Returns the backorder row or null.
     */
    public function reserve(
        Order $order,
        OrderItem $item,
        string $skuId,
        float $qty,
        ?string $warehouseId = null
    ): ?FinishedGoodsBackorder {
        if ($qty <= 0.001) {
            return null;
        }
        $sku = Sku::find($skuId);
        if ($sku && $sku->isRefill()) {
            return null;
        }

        return FinishedGoodsBackorder::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'sku_id' => $skuId,
            'warehouse_id' => $warehouseId ?: MainWarehouse::id(),
            'qty_reserved' => round($qty, 2),
            'qty_fulfilled' => 0,
            'status' => 'open',
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * After production posts finished bales, satisfy oldest open backorders first.
     *
     * @return list<array{backorder_id:string, order_id:string, qty:float}>
     */
    public function fulfillFromProduction(string $skuId, float $qtyProduced, string $warehouseId): array
    {
        if ($qtyProduced <= 0) {
            return [];
        }
        $sku = Sku::find($skuId);
        if ($sku && $sku->isRefill()) {
            return [];
        }

        $ids = app(FinishedGoodsService::class)->familyIds($skuId);
        $remaining = $qtyProduced;
        $fulfilled = [];
        $inventory = new InventoryController();

        $rows = FinishedGoodsBackorder::query()
            ->whereIn('sku_id', $ids)
            ->where('status', 'open')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($remaining <= 0.001) {
                break;
            }
            $owed = $row->remaining();
            if ($owed <= 0.001) {
                $row->update(['status' => 'fulfilled']);
                continue;
            }
            $take = min($owed, $remaining);
            $takeInt = (int) round($take);
            if ($takeInt < 1) {
                break;
            }
            $inventory->deductStock(
                $row->sku_id,
                Sku::find($row->sku_id),
                $row->warehouse_id ?: $warehouseId,
                $takeInt,
                'backorder',
                $row->id,
                'BALE'
            );
            $take = (float) $takeInt;
            $row->qty_fulfilled = round((float) $row->qty_fulfilled + $take, 2);
            if ($row->remaining() <= 0.001) {
                $row->status = 'fulfilled';
            }
            $row->save();
            $fulfilled[] = [
                'backorder_id' => $row->id,
                'order_id' => $row->order_id,
                'qty' => $take,
            ];
            $remaining -= $take;
        }

        return $fulfilled;
    }

    public function cancelForOrder(string $orderId): void
    {
        FinishedGoodsBackorder::where('order_id', $orderId)
            ->where('status', 'open')
            ->update(['status' => 'cancelled']);
    }
}

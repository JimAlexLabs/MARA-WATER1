<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\Material;
use App\Models\Sku;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Outlet/branch reference list -- the "Log a Sale" form's warehouse
     * picker (and anything else that just needs the plain list) reads it.
     */
    public function warehouses()
    {
        return response()->json([
            'success' => true,
            'data' => Warehouse::orderBy('name')->get(),
        ]);
    }

    /**
     * Display a listing of stock items
     */
    public function stockItems(Request $request)
    {
        try {
            $query = StockItem::with(['material', 'sku', 'warehouse', 'batch'])
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('item_type')) {
                $query->where('item_type', $request->item_type);
            }

            if ($request->filled('warehouse_id')) {
                $query->where('warehouse_id', $request->warehouse_id);
            }

            if ($request->filled('material_id')) {
                $query->where('material_id', $request->material_id);
            }

            if ($request->filled('sku_id')) {
                $query->where('sku_id', $request->sku_id);
            }

            if ($request->filled('low_stock')) {
                $query->where('qty', '<=', 10); // Low stock threshold
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'qty');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $stockItems = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $stockItems->items()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of stock moves
     */
    public function stockMoves(Request $request)
    {
        try {
            $query = StockMove::with(['material', 'sku', 'batch', 'warehouseFrom', 'warehouseTo', 'movedBy'])
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('move_type')) {
                $query->where('move_type', $request->move_type);
            }

            if ($request->filled('item_type')) {
                $query->where('item_type', $request->item_type);
            }

            if ($request->filled('warehouse_from_id')) {
                $query->where('warehouse_from_id', $request->warehouse_from_id);
            }

            if ($request->filled('warehouse_to_id')) {
                $query->where('warehouse_to_id', $request->warehouse_to_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $stockMoves = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $stockMoves->items()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock moves',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a stock move
     */
    public function createStockMove(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'move_type' => 'required|in:grn,issue,produce,adjust,transfer,return',
                'item_type' => 'required|in:material,sku',
                'material_id' => 'nullable|exists:materials,id',
                'sku_id' => 'nullable|exists:skus,id',
                'batch_id' => 'nullable|exists:batches,id',
                'warehouse_from_id' => 'nullable|exists:warehouses,id',
                'warehouse_to_id' => 'nullable|exists:warehouses,id',
                'qty' => 'required|numeric|min:0.001',
                'uom' => 'required|string|max:20',
                'unit_cost' => 'nullable|numeric|min:0',
                'ref_entity' => 'nullable|string|max:100',
                'ref_id' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate item type consistency
            if ($request->item_type === 'material' && !$request->material_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Material ID is required when item type is material'
                ], 422);
            }

            if ($request->item_type === 'sku' && !$request->sku_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'SKU ID is required when item type is sku'
                ], 422);
            }

            DB::beginTransaction();

            $stockMove = $this->recordMove($request->only([
                'move_type', 'item_type', 'material_id', 'sku_id', 'batch_id',
                'warehouse_from_id', 'warehouse_to_id', 'qty', 'uom', 'unit_cost',
                'ref_entity', 'ref_id',
            ]));

            $stockMove->load(['material', 'sku', 'batch', 'warehouseFrom', 'warehouseTo', 'movedBy']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock move created successfully',
                'data' => [
                    'stock_move' => $stockMove
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create stock move',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a stock move and apply its effect to stock levels. Shared by
     * the HTTP endpoint above and by other modules (e.g. Sales logging a
     * return, Production consuming raw materials) that need to post a
     * movement as part of their own flow -- callers are expected to wrap
     * this in their own DB transaction.
     *
     * $allowNegative: raw-material consumption during production needs to
     * post even when no opening stock was ever recorded for that material
     * (true pre-launch, before any GRN has been logged) -- rather than
     * blocking every production run on a shortage that isn't real, this
     * lets the balance go negative and the caller surface it as a warning.
     * Sales/returns/GRNs never pass this -- a real stock-out there should
     * still block.
     */
    public function recordMove(array $data, bool $allowNegative = false): StockMove
    {
        $stockMove = StockMove::create([
            'move_type' => $data['move_type'],
            'item_type' => $data['item_type'],
            'material_id' => $data['material_id'] ?? null,
            'sku_id' => $data['sku_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'warehouse_from_id' => $data['warehouse_from_id'] ?? null,
            'warehouse_to_id' => $data['warehouse_to_id'] ?? null,
            'qty' => $data['qty'],
            'uom' => $data['uom'],
            'unit_cost' => $data['unit_cost'] ?? null,
            'ref_entity' => $data['ref_entity'] ?? null,
            'ref_id' => $data['ref_id'] ?? null,
            'moved_by' => Auth::id(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        $this->updateStockLevels($stockMove, $allowNegative);

        return $stockMove;
    }

    /**
     * Round 2 Phase 8: the one deduction algorithm every sales entry
     * point in the app shares -- originally written for OrderController's
     * logSale() (moved here so Driver Trips can call the exact same code
     * instead of a second, drifting copy of it, per the spec's "one
     * single stock ledger that everything writes to and reads from").
     *
     * Decrements recorded finished-goods stock for a net quantity sold,
     * drawing FEFO across whichever batches of this SKU have stock at
     * this warehouse. Returns warnings (empty if everything drew cleanly)
     * rather than throwing -- a sale that would otherwise be blocked by
     * an imperfect stock count is worse than a sale that goes through and
     * leaves a visible negative-balance warning behind. $refEntity/$refId
     * tag every resulting move for traceability (e.g. 'order'/$orderId,
     * 'driver_trip'/$tripId).
     */
    public function deductStock(string $skuId, ?Sku $sku, string $warehouseId, int $qtyToDeduct, string $refEntity, string $refId, ?string $uomOverride = null): array
    {
        $warnings = [];
        // Round 4 Phase 1: the driver-trip caller passes bales, not the
        // SKU's own packaging unit (bottle/container) -- $uomOverride
        // lets it label the resulting stock_move accurately instead of
        // silently mislabeling a bale quantity as "BOTTLE".
        $uom = $uomOverride ?? ($sku->unit ?? 'BOTTLE');

        $available = StockItem::where('stock_items.item_type', 'sku')
            ->where('stock_items.sku_id', $skuId)
            ->where('stock_items.warehouse_id', $warehouseId)
            ->where('stock_items.qty', '>', 0)
            ->whereNull('stock_items.deleted_at')
            ->join('batches', 'batches.id', '=', 'stock_items.batch_id')
            ->orderByRaw('batches.expiry_date IS NULL, batches.expiry_date ASC')
            ->orderByRaw('batches.manufacture_date IS NULL, batches.manufacture_date ASC')
            ->select('stock_items.batch_id', 'stock_items.qty')
            ->get();

        $remaining = $qtyToDeduct;
        foreach ($available as $row) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $row->qty);
            $this->recordMove([
                'move_type' => 'issue',
                'item_type' => 'sku',
                'sku_id' => $skuId,
                'batch_id' => $row->batch_id,
                'warehouse_from_id' => $warehouseId,
                'qty' => $take,
                'uom' => $uom,
                'ref_entity' => $refEntity,
                'ref_id' => $refId,
            ]);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            // Not enough recorded stock anywhere for this SKU at this
            // warehouse -- draw the shortfall against whichever batch is
            // most recent (or, if this SKU has never been produced here
            // at all, skip it and warn loudly instead of guessing a batch).
            $fallbackBatchId = DB::table('batches')->where('sku_id', $skuId)->orderByDesc('manufacture_date')->value('id');
            if ($fallbackBatchId) {
                $this->recordMove([
                    'move_type' => 'issue',
                    'item_type' => 'sku',
                    'sku_id' => $skuId,
                    'batch_id' => $fallbackBatchId,
                    'warehouse_from_id' => $warehouseId,
                    'qty' => $remaining,
                    'uom' => $uom,
                    'ref_entity' => $refEntity,
                    'ref_id' => $refId,
                ], allowNegative: true);
                $warnings[] = [
                    'sku_id' => $skuId,
                    'sku_name' => $sku->name ?? 'Product',
                    'message' => ($sku->name ?? 'This product') . " sold {$remaining} {$uom} more than recorded stock at this warehouse -- balance is now negative, a production run or stock count likely needs entering.",
                ];
            } else {
                $warnings[] = [
                    'sku_id' => $skuId,
                    'sku_name' => $sku->name ?? 'Product',
                    'message' => ($sku->name ?? 'This product') . " has never been produced at this warehouse -- {$remaining} {$uom} sold could not be deducted from any batch.",
                ];
            }
        }

        return $warnings;
    }

    /**
     * Get inventory statistics
     */
    public function statistics(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());

            // Stock items statistics
            $stockStats = StockItem::whereNull('deleted_at')
                ->selectRaw('
                    COUNT(*) as total_items,
                    COUNT(CASE WHEN item_type = "material" THEN 1 END) as material_items,
                    COUNT(CASE WHEN item_type = "sku" THEN 1 END) as sku_items,
                    SUM(qty) as total_quantity,
                    COUNT(CASE WHEN qty <= 10 THEN 1 END) as low_stock_items,
                    COUNT(CASE WHEN qty = 0 THEN 1 END) as out_of_stock_items
                ')
                ->first();

            // Stock moves statistics
            $moveStats = StockMove::whereNull('deleted_at')
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_moves,
                    COUNT(CASE WHEN move_type = "grn" THEN 1 END) as grn_moves,
                    COUNT(CASE WHEN move_type = "issue" THEN 1 END) as issue_moves,
                    COUNT(CASE WHEN move_type = "produce" THEN 1 END) as produce_moves,
                    COUNT(CASE WHEN move_type = "transfer" THEN 1 END) as transfer_moves,
                    COUNT(CASE WHEN move_type = "return" THEN 1 END) as return_moves,
                    SUM(CASE WHEN move_type IN ("grn", "produce") THEN qty ELSE 0 END) as total_in,
                    SUM(CASE WHEN move_type IN ("issue", "adjust") THEN qty ELSE 0 END) as total_out
                ')
                ->first();

            // Top materials by quantity
            $topMaterials = StockItem::whereNull('deleted_at')
                ->where('item_type', 'material')
                ->with(['material'])
                ->orderByDesc('qty')
                ->limit(10)
                ->get(['id', 'material_id', 'qty']);

            // Top SKUs by quantity
            $topSkus = StockItem::whereNull('deleted_at')
                ->where('item_type', 'sku')
                ->with(['sku'])
                ->orderByDesc('qty')
                ->limit(10)
                ->get(['id', 'sku_id', 'qty']);

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ],
                    'stock_statistics' => [
                        'total_items' => $stockStats->total_items,
                        'material_items' => $stockStats->material_items,
                        'sku_items' => $stockStats->sku_items,
                        'total_quantity' => round($stockStats->total_quantity, 3),
                        'low_stock_items' => $stockStats->low_stock_items,
                        'out_of_stock_items' => $stockStats->out_of_stock_items,
                    ],
                    'move_statistics' => [
                        'total_moves' => $moveStats->total_moves,
                        'grn_moves' => $moveStats->grn_moves,
                        'issue_moves' => $moveStats->issue_moves,
                        'produce_moves' => $moveStats->produce_moves,
                        'transfer_moves' => $moveStats->transfer_moves,
                        'return_moves' => $moveStats->return_moves,
                        'total_in' => round($moveStats->total_in, 3),
                        'total_out' => round($moveStats->total_out, 3),
                    ],
                    'top_materials' => $topMaterials,
                    'top_skus' => $topSkus,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get stock levels by warehouse
     */
    public function stockByWarehouse(Request $request, $warehouseId)
    {
        try {
            $query = StockItem::with(['material', 'sku', 'batch'])
                ->where('warehouse_id', $warehouseId)
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('item_type')) {
                $query->where('item_type', $request->item_type);
            }

            if ($request->filled('low_stock')) {
                $query->where('qty', '<=', 10);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'qty');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $stockItems = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'stock_items' => $stockItems->items(),
                    'pagination' => [
                        'current_page' => $stockItems->currentPage(),
                        'per_page' => $stockItems->perPage(),
                        'total' => $stockItems->total(),
                        'last_page' => $stockItems->lastPage(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock by warehouse',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Warehouse stock card -- replaces "MAIN STOCK WARE HOUSE": one item,
     * one warehouse, opening/in/out/returns/closing per day, read straight
     * off the movement ledger rather than kept as a separately maintained
     * number.
     */
    public function stockCard(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'item_type' => 'required|in:sku,material',
                'item_id' => 'required|string',
                'warehouse_id' => 'required|exists:warehouses,id',
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
            ]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $itemType = $request->item_type;
            $itemId = $request->item_id;
            $warehouseId = $request->warehouse_id;
            $idColumn = $itemType === 'sku' ? 'sku_id' : 'material_id';
            $dateFrom = $request->date_from;
            $dateTo = $request->date_to;

            $baseQuery = fn () => StockMove::whereNull('deleted_at')
                ->where('item_type', $itemType)
                ->where($idColumn, $itemId);

            // Opening balance: net effect of every move before this period.
            $openingIn = (clone $baseQuery())->where('warehouse_to_id', $warehouseId)
                ->where('moved_at', '<', $dateFrom)->sum('qty');
            $openingOut = (clone $baseQuery())->where('warehouse_from_id', $warehouseId)
                ->where('moved_at', '<', $dateFrom)->sum('qty');
            $opening = (float) $openingIn - (float) $openingOut;

            $moves = (clone $baseQuery())
                ->where(function ($q) use ($warehouseId) {
                    $q->where('warehouse_to_id', $warehouseId)->orWhere('warehouse_from_id', $warehouseId);
                })
                ->whereBetween('moved_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
                ->orderBy('moved_at')
                ->get();

            $byDay = [];
            $running = $opening;
            $cursor = \Carbon\Carbon::parse($dateFrom);
            $end = \Carbon\Carbon::parse($dateTo);
            while ($cursor->lte($end)) {
                $byDay[$cursor->toDateString()] = ['opening' => $running, 'in' => 0.0, 'out' => 0.0, 'returns' => 0.0, 'closing' => $running];
                $cursor->addDay();
            }

            foreach ($moves as $move) {
                $day = \Carbon\Carbon::parse($move->moved_at)->toDateString();
                if (!isset($byDay[$day])) {
                    continue;
                }
                $qty = (float) $move->qty;
                if ($move->warehouse_to_id === $warehouseId) {
                    if ($move->move_type === 'return') {
                        $byDay[$day]['returns'] += $qty;
                    } else {
                        $byDay[$day]['in'] += $qty;
                    }
                }
                if ($move->warehouse_from_id === $warehouseId) {
                    $byDay[$day]['out'] += $qty;
                }
            }

            // Roll opening/closing forward day to day now that each day's
            // in/out/returns are known.
            $running = $opening;
            foreach ($byDay as $day => &$row) {
                $row['opening'] = $running;
                $row['closing'] = $running + $row['in'] - $row['out'] + $row['returns'];
                $running = $row['closing'];
            }
            unset($row);

            return response()->json([
                'success' => true,
                'data' => [
                    'item_type' => $itemType,
                    'item_id' => $itemId,
                    'warehouse_id' => $warehouseId,
                    'opening_balance' => round($opening, 3),
                    'days' => collect($byDay)->map(fn ($row, $date) => array_merge(['date' => $date], array_map(fn ($v) => round($v, 3), $row)))->values(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to build stock card', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Round 3 Phase 9: "FINISHED STOCK RECONCILIATION TOOL" exact-format
     * .xlsx -- see StockReconciliationExportService for the layout.
     */
    public function stockReconciliationExport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|exists:warehouses,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return (new \App\Services\StockReconciliationExportService())->generate($request->warehouse_id, $request->date_from, $request->date_to);
    }

    /**
     * Stock reconciliation -- per SKU: opening qty, produced, issued
     * (sold), returned, closing qty, and the same in value terms using
     * the default price list's unit price. Generated on demand from the
     * movement ledger + price list, never a manually re-entered table.
     */
    public function reconciliation(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());
            $warehouseId = $request->get('warehouse_id');

            $prices = \App\Models\PriceListItem::whereHas('priceList', fn ($q) => $q->where('is_default', true))
                ->pluck('unit_price', 'sku_id');

            $skus = Sku::where('active', true)->orderBy('brand')->orderBy('name')->get();

            $rows = $skus->map(function ($sku) use ($dateFrom, $dateTo, $warehouseId, $prices) {
                $moves = StockMove::whereNull('deleted_at')
                    ->where('item_type', 'sku')->where('sku_id', $sku->id)
                    ->when($warehouseId, fn ($q) => $q->where(function ($w) use ($warehouseId) {
                        $w->where('warehouse_to_id', $warehouseId)->orWhere('warehouse_from_id', $warehouseId);
                    }));

                $openingIn = (clone $moves)->where('moved_at', '<', $dateFrom)->sum(DB::raw('CASE WHEN warehouse_to_id IS NOT NULL THEN qty ELSE 0 END'));
                $openingOut = (clone $moves)->where('moved_at', '<', $dateFrom)->sum(DB::raw('CASE WHEN warehouse_from_id IS NOT NULL THEN qty ELSE 0 END'));
                $opening = (float) $openingIn - (float) $openingOut;

                $periodMoves = (clone $moves)->whereBetween('moved_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"]);
                $produced = (clone $periodMoves)->where('move_type', 'produce')->sum('qty');
                $issued = (clone $periodMoves)->where('move_type', 'issue')->sum('qty');
                $returned = (clone $periodMoves)->where('move_type', 'return')->sum('qty');

                $closing = $opening + (float) $produced - (float) $issued + (float) $returned;
                $unitPrice = (float) ($prices[$sku->id] ?? 0);

                return [
                    'sku_id' => $sku->id,
                    'sku' => ['id' => $sku->id, 'code' => $sku->code, 'name' => $sku->name, 'brand' => $sku->brand],
                    'unit_price' => $unitPrice,
                    'opening_qty' => round($opening, 3),
                    'produced_qty' => round((float) $produced, 3),
                    'issued_qty' => round((float) $issued, 3),
                    'returned_qty' => round((float) $returned, 3),
                    'closing_qty' => round($closing, 3),
                    'opening_value' => round($opening * $unitPrice, 2),
                    'closing_value' => round($closing * $unitPrice, 2),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => ['from' => $dateFrom, 'to' => $dateTo],
                    'items' => $rows,
                    'total_closing_value' => round($rows->sum('closing_value'), 2),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to build reconciliation', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Raw materials usage -- replaces "RAW MATERIAL REAL": per material,
     * opening balance, received (GRN), used (production issue), closing
     * balance -- the same opening-used-balance pattern, automated.
     */
    public function materialsUsage(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());
            $warehouseId = $request->get('warehouse_id');

            $materials = Material::orderBy('category')->orderBy('name')->get();

            $rows = $materials->map(function ($material) use ($dateFrom, $dateTo, $warehouseId) {
                $moves = StockMove::whereNull('deleted_at')
                    ->where('item_type', 'material')->where('material_id', $material->id)
                    ->when($warehouseId, fn ($q) => $q->where(function ($w) use ($warehouseId) {
                        $w->where('warehouse_to_id', $warehouseId)->orWhere('warehouse_from_id', $warehouseId);
                    }));

                $openingIn = (clone $moves)->where('moved_at', '<', $dateFrom)->sum(DB::raw('CASE WHEN warehouse_to_id IS NOT NULL THEN qty ELSE 0 END'));
                $openingOut = (clone $moves)->where('moved_at', '<', $dateFrom)->sum(DB::raw('CASE WHEN warehouse_from_id IS NOT NULL THEN qty ELSE 0 END'));
                $opening = (float) $openingIn - (float) $openingOut;

                $periodMoves = (clone $moves)->whereBetween('moved_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"]);
                $received = (clone $periodMoves)->where('move_type', 'grn')->sum('qty');
                $used = (clone $periodMoves)->where('move_type', 'issue')->sum('qty');

                $closing = $opening + (float) $received - (float) $used;

                return [
                    'material_id' => $material->id,
                    'material' => ['id' => $material->id, 'code' => $material->code, 'name' => $material->name, 'category' => $material->category, 'uom' => $material->uom],
                    'opening_balance' => round($opening, 3),
                    'received' => round((float) $received, 3),
                    'used' => round((float) $used, 3),
                    'closing_balance' => round($closing, 3),
                    'below_min_level' => $closing < (float) $material->min_level,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => ['from' => $dateFrom, 'to' => $dateTo],
                    'materials' => $rows,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to build materials usage report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Refills tracking -- not a fifth separate sheet, just a filtered
     * slice of production (and sales, from Phase 7) by product type
     * "Refill", by size, by day.
     */
    public function refills(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());

            $refillSkuIds = Sku::where('brand', 'Refill')->pluck('id');

            $production = \App\Models\PackagingRun::with('sku')
                ->whereIn('sku_id', $refillSkuIds)
                ->whereNotNull('run_end')
                ->whereDate('run_end', '>=', $dateFrom)
                ->whereDate('run_end', '<=', $dateTo)
                ->selectRaw('sku_id, DATE(run_end) as date, SUM(good_qty) as qty_produced')
                ->groupBy('sku_id', 'date')
                ->orderBy('date')
                ->get()
                ->map(fn ($r) => [
                    'sku_id' => $r->sku_id,
                    'sku' => $r->sku,
                    'date' => $r->date,
                    'qty_produced' => (int) $r->qty_produced,
                ]);

            // Round 2 Phase 12 finding: this used to read order_items only
            // -- missing driver trip sales entirely (a driver selling
            // Refills is exactly as real as an outlet sale, Round 2 Phase
            // 6-8) and counting a draft order's phantom qty as real
            // (no Order::completedSale() filter). Both fixed here,
            // combining both sources per SKU/day the same way Analytics
            // (Phase 9) and Costing & P&L (Phase 12) do.
            $orderSales = \App\Models\OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('order_items.sku_id', $refillSkuIds)
                ->whereNull('orders.deleted_at')->whereNull('order_items.deleted_at')
                ->whereNotNull('orders.payment_method')
                ->whereDate('orders.order_date', '>=', $dateFrom)
                ->whereDate('orders.order_date', '<=', $dateTo)
                ->selectRaw('order_items.sku_id, orders.order_date as date, SUM(order_items.qty) as qty_dispatched, SUM(order_items.qty_returned) as qty_returned, SUM(order_items.qty - order_items.qty_returned) as qty_net_sold')
                ->groupBy('order_items.sku_id', 'orders.order_date')
                ->get()
                ->keyBy(fn ($r) => $r->sku_id . '|' . $r->date);

            $tripSales = \App\Models\DriverTripItem::join('driver_trips', 'driver_trips.id', '=', 'driver_trip_items.driver_trip_id')
                ->whereIn('driver_trip_items.sku_id', $refillSkuIds)
                ->whereNull('driver_trips.deleted_at')
                ->whereDate('driver_trips.trip_date', '>=', $dateFrom)
                ->whereDate('driver_trips.trip_date', '<=', $dateTo)
                ->selectRaw('driver_trip_items.sku_id, driver_trips.trip_date as date, SUM(driver_trip_items.qty_carried) as qty_dispatched, SUM(driver_trip_items.qty_returned) as qty_returned, SUM(driver_trip_items.qty_sold) as qty_net_sold')
                ->groupBy('driver_trip_items.sku_id', 'driver_trips.trip_date')
                ->get()
                ->keyBy(fn ($r) => $r->sku_id . '|' . $r->date);

            $skusById = Sku::whereIn('id', $refillSkuIds)->get()->keyBy('id');
            $keys = collect($orderSales->keys())->merge($tripSales->keys())->unique()->sort()->values();
            $sales = $keys->map(function ($key) use ($orderSales, $tripSales, $skusById) {
                [$skuId, $date] = explode('|', $key, 2);
                $o = $orderSales->get($key);
                $t = $tripSales->get($key);
                return [
                    'sku_id' => $skuId,
                    'sku' => $skusById->get($skuId),
                    'date' => $date,
                    'qty_dispatched' => (int) (($o->qty_dispatched ?? 0) + ($t->qty_dispatched ?? 0)),
                    'qty_returned' => (int) (($o->qty_returned ?? 0) + ($t->qty_returned ?? 0)),
                    'qty_net_sold' => (int) (($o->qty_net_sold ?? 0) + ($t->qty_net_sold ?? 0)),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => ['from' => $dateFrom, 'to' => $dateTo],
                    'sizes' => Sku::where('brand', 'Refill')->orderBy('size_liters')->get(['id', 'name', 'size_liters']),
                    'production_by_day' => $production,
                    'sales_by_day' => $sales,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to build refills report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get low stock items. Without an explicit ?threshold=, this compares
     * each item against ITS OWN reorder point -- materials.min_level or
     * skus.reorder_threshold (falling back to the old flat 10 for SKUs
     * that haven't had one set yet) -- rather than one number for every
     * item regardless of what it is.
     */
    public function lowStock(Request $request)
    {
        try {
            $threshold = $request->filled('threshold') ? (float) $request->threshold : null;

            $query = StockItem::with(['material', 'sku', 'warehouse'])
                ->whereNull('deleted_at');

            if ($threshold !== null) {
                $query->where('qty', '<=', $threshold);
            } else {
                $query->where(function ($q) {
                    $q->whereHas('material', function ($m) {
                        $m->whereColumn('stock_items.qty', '<=', 'materials.min_level');
                    })->orWhereHas('sku', function ($s) {
                        $s->whereRaw('stock_items.qty <= COALESCE(skus.reorder_threshold, 10)');
                    });
                });
            }

            // Apply filters
            if ($request->filled('item_type')) {
                $query->where('item_type', $request->item_type);
            }

            if ($request->filled('warehouse_id')) {
                $query->where('warehouse_id', $request->warehouse_id);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'qty');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $stockItems = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'threshold' => $threshold, // null means "per-item reorder threshold", not a single number
                    'stock_items' => $stockItems->items(),
                    'pagination' => [
                        'current_page' => $stockItems->currentPage(),
                        'per_page' => $stockItems->perPage(),
                        'total' => $stockItems->total(),
                        'last_page' => $stockItems->lastPage(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve low stock items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update stock levels based on stock move
     */
    private function updateStockLevels($stockMove, bool $allowNegative = false)
    {
        // Handle outgoing stock (from warehouse)
        if ($stockMove->warehouse_from_id) {
            $this->updateStockItem(
                $stockMove->item_type,
                $stockMove->material_id,
                $stockMove->sku_id,
                $stockMove->batch_id,
                $stockMove->warehouse_from_id,
                -$stockMove->qty,
                $allowNegative
            );
        }

        // Handle incoming stock (to warehouse)
        if ($stockMove->warehouse_to_id) {
            $this->updateStockItem(
                $stockMove->item_type,
                $stockMove->material_id,
                $stockMove->sku_id,
                $stockMove->batch_id,
                $stockMove->warehouse_to_id,
                $stockMove->qty,
                $allowNegative
            );
        }
    }

    /**
     * Update specific stock item
     */
    private function updateStockItem($itemType, $materialId, $skuId, $batchId, $warehouseId, $qtyChange, bool $allowNegative = false)
    {
        $stockItem = StockItem::where('item_type', $itemType)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at');

        if ($itemType === 'material') {
            $stockItem->where('material_id', $materialId);
        } else {
            $stockItem->where('sku_id', $skuId)
                     ->where('batch_id', $batchId);
        }

        $stockItem = $stockItem->first();

        if ($stockItem) {
            // Update existing stock item
            $newQty = $stockItem->qty + $qtyChange;
            if ($newQty < 0 && !$allowNegative) {
                throw new \Exception('Insufficient stock for this operation');
            }

            $stockItem->update([
                'qty' => $newQty,
                'updated_by' => Auth::id(),
            ]);
        } else {
            // Create new stock item if it doesn't exist and we're adding stock
            if ($qtyChange > 0 || $allowNegative) {
                StockItem::create([
                    'item_type' => $itemType,
                    'material_id' => $materialId,
                    'sku_id' => $skuId,
                    'batch_id' => $batchId,
                    'warehouse_id' => $warehouseId,
                    'qty' => $qtyChange,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            } else {
                throw new \Exception('Cannot reduce stock for non-existent item');
            }
        }
    }

    /**
     * Round 5B seam: stable inventory + production figures for the
     * Discrepancies page (Claude Code Round 5A) and Reports.
     * expected_on_hand ≈ opening + produced - sold (+ returns).
     */
    public function figures(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        $onHand = (float) StockItem::where('item_type', 'sku')->whereNull('deleted_at')->sum('qty');
        $produced = (float) StockMove::whereNull('deleted_at')->where('item_type', 'sku')->where('move_type', 'produce')
            ->whereBetween('moved_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])->sum('qty');
        $issued = (float) StockMove::whereNull('deleted_at')->where('item_type', 'sku')->where('move_type', 'issue')
            ->whereBetween('moved_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])->sum('qty');
        $returned = (float) StockMove::whereNull('deleted_at')->where('item_type', 'sku')->where('move_type', 'return')
            ->whereBetween('moved_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])->sum('qty');

        $materialOnHand = (float) StockItem::where('item_type', 'material')->whereNull('deleted_at')->sum('qty');
        $batchesReceived = \App\Models\MaterialBatch::whereBetween('purchase_date', [$dateFrom, $dateTo])->count();
        $batchesPendingQuality = \App\Models\MaterialBatch::where('quality_status', 'pending')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'finished_goods' => [
                    'on_hand_qty' => round($onHand, 3),
                    'produced_qty' => round($produced, 3),
                    'issued_qty' => round($issued, 3),
                    'returned_qty' => round($returned, 3),
                    'net_movement' => round($produced - $issued + $returned, 3),
                ],
                'materials' => [
                    'on_hand_qty' => round($materialOnHand, 3),
                    'batches_received' => $batchesReceived,
                    'batches_pending_quality' => $batchesPendingQuality,
                ],
            ],
        ]);
    }

    public function listReconciliationReports(Request $request)
    {
        $reports = \App\Models\StockReconciliationReport::with('warehouse')
            ->orderByDesc('period_end')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'period_type' => $r->period_type,
                'period_start' => $r->period_start,
                'period_end' => $r->period_end,
                'warehouse' => $r->warehouse,
                'filename' => $r->filename,
                'size_bytes' => $r->size_bytes,
                'generated_by' => $r->generated_by,
                'created_at' => $r->created_at,
            ]);

        return response()->json(['success' => true, 'data' => $reports]);
    }

    public function createReconciliationReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'period_type' => 'nullable|in:weekly,monthly,on_demand',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $service = app(\App\Services\StockReconciliationReportService::class);
        $type = $request->get('period_type', 'on_demand');

        try {
            if ($type === 'weekly' && !$request->filled('date_from')) {
                $report = $service->runWeekly();
            } elseif ($type === 'monthly' && !$request->filled('date_from')) {
                $report = $service->runMonthly();
            } else {
                if (!$request->filled('date_from') || !$request->filled('date_to')) {
                    return response()->json(['success' => false, 'message' => 'date_from and date_to are required for on-demand reports'], 422);
                }
                $report = $service->generateAndStore(
                    $type === 'on_demand' ? 'on_demand' : $type,
                    $request->date_from,
                    $request->date_to,
                    $request->warehouse_id,
                    Auth::id()
                );
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock reconciliation report generated',
            'data' => [
                'id' => $report->id,
                'filename' => $report->filename,
                'period_type' => $report->period_type,
                'period_start' => $report->period_start,
                'period_end' => $report->period_end,
                'size_bytes' => $report->size_bytes,
            ],
        ], 201);
    }

    public function downloadReconciliationReport($id)
    {
        $report = \App\Models\StockReconciliationReport::find($id);
        if (!$report) {
            return response()->json(['success' => false, 'message' => 'Report not found'], 404);
        }

        $binary = base64_decode($report->payload);
        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $report->filename . '"',
        ]);
    }
}

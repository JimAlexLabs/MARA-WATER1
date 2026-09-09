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

            // Create stock move
            $stockMove = StockMove::create([
                'move_type' => $request->move_type,
                'item_type' => $request->item_type,
                'material_id' => $request->material_id,
                'sku_id' => $request->sku_id,
                'batch_id' => $request->batch_id,
                'warehouse_from_id' => $request->warehouse_from_id,
                'warehouse_to_id' => $request->warehouse_to_id,
                'qty' => $request->qty,
                'uom' => $request->uom,
                'unit_cost' => $request->unit_cost,
                'ref_entity' => $request->ref_entity,
                'ref_id' => $request->ref_id,
                'moved_by' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Update stock levels
            $this->updateStockLevels($stockMove);

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
     * Get low stock items
     */
    public function lowStock(Request $request)
    {
        try {
            $threshold = $request->get('threshold', 10);

            $query = StockItem::with(['material', 'sku', 'warehouse'])
                ->where('qty', '<=', $threshold)
                ->whereNull('deleted_at');

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
                    'threshold' => $threshold,
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
    private function updateStockLevels($stockMove)
    {
        // Handle outgoing stock (from warehouse)
        if ($stockMove->warehouse_from_id) {
            $this->updateStockItem(
                $stockMove->item_type,
                $stockMove->material_id,
                $stockMove->sku_id,
                $stockMove->batch_id,
                $stockMove->warehouse_from_id,
                -$stockMove->qty
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
                $stockMove->qty
            );
        }
    }

    /**
     * Update specific stock item
     */
    private function updateStockItem($itemType, $materialId, $skuId, $batchId, $warehouseId, $qtyChange)
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
            if ($newQty < 0) {
                throw new \Exception('Insufficient stock for this operation');
            }
            
            $stockItem->update([
                'qty' => $newQty,
                'updated_by' => Auth::id(),
            ]);
        } else {
            // Create new stock item if it doesn't exist and we're adding stock
            if ($qtyChange > 0) {
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
}

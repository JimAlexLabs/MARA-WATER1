<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Round 3 Phase 10: recording a raw-material/packaging stock purchase
 * as its own traceable batch (batch number, supplier, unit cost,
 * quantity received) instead of only being able to add the quantity to
 * a material's single running-total line. See the
 * create_material_batches_table migration docblock for how this stays
 * consistent with the existing rollup/consumption stock logic.
 */
class MaterialBatchController extends Controller
{
    public function index(Request $request)
    {
        $query = MaterialBatch::with(['material', 'warehouse', 'receivedBy']);

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->material_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        $batches = $query->orderByDesc('purchase_date')->paginate($request->get('limit', 20));

        return response()->json([
            'success' => true,
            'data' => $batches->items(),
            'meta' => ['current_page' => $batches->currentPage(), 'last_page' => $batches->lastPage(), 'total' => $batches->total()],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Either point at an existing material, or describe a brand
            // new one to create in the same write -- so recording a
            // purchase of a material that's never been bought before
            // doesn't require a separate trip to the Materials screen
            // first.
            'material_id' => 'required_without:new_material|nullable|exists:materials,id',
            'new_material' => 'required_without:material_id|nullable|array',
            'new_material.code' => 'required_with:new_material|string|max:20|unique:materials,code',
            'new_material.name' => 'required_with:new_material|string|max:200',
            'new_material.category' => 'required_with:new_material|string|max:50',
            'new_material.uom' => 'required_with:new_material|string|max:10',
            'new_material.min_level' => 'nullable|numeric|min:0',

            'batch_number' => 'nullable|string|max:50',
            'purchase_date' => 'required|date',
            'supplier_name' => 'nullable|string|max:200',
            'unit_cost' => 'required|numeric|min:0',
            'qty_received' => 'required|numeric|min:0.001',
            'warehouse_id' => 'required|exists:warehouses,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $material = $request->material_id
                    ? Material::findOrFail($request->material_id)
                    : Material::create([
                        'code' => $request->input('new_material.code'),
                        'name' => $request->input('new_material.name'),
                        'category' => $request->input('new_material.category'),
                        'uom' => $request->input('new_material.uom'),
                        'unit_cost' => $request->unit_cost,
                        'is_consumable' => true,
                        'min_level' => $request->input('new_material.min_level') ?? 0,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);

                $batchNumber = $request->filled('batch_number')
                    ? $request->batch_number
                    : $material->code . '-' . $request->purchase_date . '-' . strtoupper(Str::random(4));

                $batch = MaterialBatch::create([
                    'material_id' => $material->id,
                    'batch_number' => $batchNumber,
                    'purchase_date' => $request->purchase_date,
                    'supplier_name' => $request->supplier_name,
                    'unit_cost' => $request->unit_cost,
                    'qty_received' => $request->qty_received,
                    'warehouse_id' => $request->warehouse_id,
                    'received_by' => Auth::id(),
                    'notes' => $request->notes,
                ]);

                // Same shared stock-ledger entry point every other move in
                // the app uses -- this is what actually rolls the batch's
                // quantity into the material's existing running-total
                // StockItem for this warehouse (unchanged code path).
                $inventory = new InventoryController();
                $inventory->recordMove([
                    'move_type' => 'grn',
                    'item_type' => 'material',
                    'material_id' => $material->id,
                    'warehouse_to_id' => $request->warehouse_id,
                    'qty' => $request->qty_received,
                    'uom' => $material->uom,
                    'unit_cost' => $request->unit_cost,
                    'ref_entity' => 'material_batch',
                    'ref_id' => $batch->id,
                ]);

                // Latest purchase cost becomes the material's reference
                // unit_cost (used elsewhere for costing) -- a simple
                // "most recent price", not a weighted average.
                $material->update(['unit_cost' => $request->unit_cost, 'updated_by' => Auth::id()]);

                return $batch;
            });

            $result->load(['material', 'warehouse', 'receivedBy']);

            return response()->json([
                'success' => true,
                'message' => 'Batch received and stock updated',
                'data' => ['material_batch' => $result],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record batch',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

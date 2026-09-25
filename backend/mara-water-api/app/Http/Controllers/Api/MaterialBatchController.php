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
 * Round 3 Phase 10 + Round 5B Phase 1: material purchase as a
 * traceable batch, with a Warehouse Audit quality gate on receipt
 * so every arrival is tied to a quality record from day one.
 */
class MaterialBatchController extends Controller
{
    public function index(Request $request)
    {
        $query = MaterialBatch::with(['material', 'warehouse', 'receivedBy', 'qualityCheckedBy']);

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->material_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('quality_status')) {
            $query->where('quality_status', $request->quality_status);
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
            'supplier_code' => 'nullable|string|in:FINELINE,BLUEPLUS,BLOWPLAST,OTHER',
            'sku_id' => 'nullable|exists:skus,id',
            'unit_cost' => 'required|numeric|min:0',
            // Ops brief §3: bags received is the capture unit; qty_received
            // stays as bags for ledger compatibility. Bales are computed.
            'bags_received' => 'nullable|numeric|min:0.001',
            'qty_received' => 'required_without:bags_received|nullable|numeric|min:0.001',
            'bottles_per_bag' => 'nullable|numeric|min:0.001',
            'bottles_per_bale' => 'nullable|numeric|min:0.001',
            'warehouse_id' => 'required|exists:warehouses,id',
            'transport_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'document_path' => 'nullable|string|max:500',
            'arrival_type' => 'nullable|in:goods_received',

            'quality_status' => 'nullable|in:pending,passed,failed',
            'quality_notes' => 'nullable|string|max:2000',
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
                        'uom' => $request->input('new_material.uom', 'bag'),
                        'unit_cost' => $request->unit_cost,
                        'is_consumable' => true,
                        'min_level' => $request->input('new_material.min_level') ?? 0,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]);

                $batchNumber = $request->filled('batch_number')
                    ? $request->batch_number
                    : $material->code . '-' . $request->purchase_date . '-' . strtoupper(Str::random(4));

                $bags = (float) ($request->bags_received ?? $request->qty_received);
                $bottlesPerBag = (float) ($request->bottles_per_bag
                    ?? config('mara_operations.default_conversions.bottles_per_bag', 24));
                $bottlesPerBale = (float) ($request->bottles_per_bale
                    ?? config('mara_operations.bottles_per_bale', 24));
                // Hard rule: never typed — always derived.
                $balesExpected = ($bottlesPerBag > 0 && $bottlesPerBale > 0)
                    ? round(($bags * $bottlesPerBag) / $bottlesPerBale, 3)
                    : 0;

                // Prefer conversion table when supplier+SKU provided
                if ($request->filled('supplier_code') && $request->filled('sku_id')) {
                    $conv = \App\Models\SkuPackageConversion::where('supplier_code', strtoupper($request->supplier_code))
                        ->where('sku_id', $request->sku_id)
                        ->first();
                    if ($conv) {
                        $bottlesPerBag = (float) $conv->bottles_per_bag;
                        $bottlesPerBale = (float) $conv->bottles_per_bale;
                        $balesExpected = $conv->bagsToBales($bags);
                    }
                }

                $qualityStatus = $request->input('quality_status', 'pending');
                $qualityCheckedAt = null;
                $qualityCheckedBy = null;
                if (in_array($qualityStatus, ['passed', 'failed'], true)) {
                    $qualityCheckedAt = now();
                    $qualityCheckedBy = Auth::id();
                }

                $supplierCode = $request->supplier_code
                    ? strtoupper($request->supplier_code)
                    : null;
                $supplierName = $request->supplier_name;
                if (!$supplierName && $supplierCode) {
                    $known = collect(config('mara_operations.bottle_suppliers', []))
                        ->firstWhere('code', $supplierCode);
                    $supplierName = $known['name'] ?? $supplierCode;
                }

                $batch = MaterialBatch::create([
                    'material_id' => $material->id,
                    'sku_id' => $request->sku_id,
                    'batch_number' => $batchNumber,
                    'arrival_type' => 'goods_received',
                    'purchase_date' => $request->purchase_date,
                    'supplier_name' => $supplierName,
                    'supplier_code' => $supplierCode,
                    'unit_cost' => $request->unit_cost,
                    'transport_cost' => $request->transport_cost,
                    'qty_received' => $bags,
                    'bags_received' => $bags,
                    'bottles_per_bag' => $bottlesPerBag,
                    'bottles_per_bale' => $bottlesPerBale,
                    'bales_expected' => $balesExpected,
                    'qty_remaining' => $bags,
                    'package_unit' => 'bag',
                    'warehouse_id' => $request->warehouse_id,
                    'received_by' => Auth::id(),
                    'notes' => $request->notes,
                    'document_path' => $request->document_path,
                    'quality_status' => $qualityStatus,
                    'quality_checked_at' => $qualityCheckedAt,
                    'quality_checked_by' => $qualityCheckedBy,
                    'quality_notes' => $request->quality_notes,
                ]);

                if ($qualityStatus === 'passed') {
                    $inventory = new InventoryController();
                    $inventory->recordMove([
                        'move_type' => 'grn',
                        'item_type' => 'material',
                        'material_id' => $material->id,
                        'warehouse_to_id' => $request->warehouse_id,
                        'qty' => $bags,
                        'uom' => 'bag',
                        'unit_cost' => $request->unit_cost,
                        'ref_entity' => 'material_batch',
                        'ref_id' => $batch->id,
                    ]);
                    $material->update(['unit_cost' => $request->unit_cost, 'updated_by' => Auth::id()]);
                }

                return $batch;
            });

            $result->load(['material', 'warehouse', 'receivedBy', 'qualityCheckedBy']);

            return response()->json([
                'success' => true,
                'message' => $result->quality_status === 'passed'
                    ? "Stock arrival recorded — {$result->bales_expected} bales expected from {$result->bags_received} bags"
                    : 'Stock arrival recorded — quality ' . $result->quality_status . " ({$result->bales_expected} bales expected; stock posts when quality is passed)",
                'data' => ['material_batch' => $result],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record stock arrival',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Round 5B Phase 1: complete / update the Warehouse Audit quality
     * check on a received batch. Passing for the first time posts GRN stock.
     */
    public function completeQuality(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'quality_status' => 'required|in:passed,failed',
            'quality_notes' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $batch = DB::transaction(function () use ($request, $id) {
                $batch = MaterialBatch::with('material')->lockForUpdate()->findOrFail($id);
                $wasPassed = $batch->quality_status === 'passed';

                $batch->update([
                    'quality_status' => $request->quality_status,
                    'quality_notes' => $request->quality_notes ?? $batch->quality_notes,
                    'quality_checked_at' => now(),
                    'quality_checked_by' => Auth::id(),
                ]);

                if ($request->quality_status === 'passed' && !$wasPassed) {
                    $inventory = new InventoryController();
                    $inventory->recordMove([
                        'move_type' => 'grn',
                        'item_type' => 'material',
                        'material_id' => $batch->material_id,
                        'warehouse_to_id' => $batch->warehouse_id,
                        'qty' => $batch->qty_received,
                        'uom' => 'bag',
                        'unit_cost' => $batch->unit_cost,
                        'ref_entity' => 'material_batch',
                        'ref_id' => $batch->id,
                    ]);
                    $batch->material->update([
                        'unit_cost' => $batch->unit_cost,
                        'updated_by' => Auth::id(),
                    ]);
                }

                return $batch->fresh(['material', 'warehouse', 'receivedBy', 'qualityCheckedBy']);
            });

            return response()->json([
                'success' => true,
                'message' => 'Quality check recorded',
                'data' => ['material_batch' => $batch],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record quality check',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

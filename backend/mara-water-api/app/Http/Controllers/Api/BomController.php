<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BomItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * Bill of Materials management -- Phase 8. bom_items already existed as a
 * real table (sku_id, material_id, qty_per_unit, uom) but had no model or
 * controller at all. This is what PackagingRunController reads to
 * auto-deduct raw materials per unit produced, and what a production
 * manager needs to actually set up ("one Premium 1L bottle takes 1
 * preform + 1 label + ...") before that deduction means anything.
 */
class BomController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = BomItem::with(['sku', 'material'])->whereNull('deleted_at');

            if ($request->filled('sku_id')) {
                $query->where('sku_id', $request->sku_id);
            }

            $items = $query->orderBy('created_at')->get();

            return response()->json(['success' => true, 'data' => $items]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve bill of materials',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sku_id' => 'required|exists:skus,id',
            'material_id' => 'required|exists:materials,id',
            'qty_per_unit' => 'required|numeric|min:0.0001',
            'uom' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // sku_id + material_id is a real unique key (uk_bom_items_sku_material)
        if (BomItem::where('sku_id', $request->sku_id)->where('material_id', $request->material_id)->whereNull('deleted_at')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This material is already on this product\'s bill of materials -- edit the existing line instead',
                'errors' => ['material_id' => ['Already added to this product']],
            ], 422);
        }

        $bomItem = BomItem::create([
            'sku_id' => $request->sku_id,
            'material_id' => $request->material_id,
            'qty_per_unit' => $request->qty_per_unit,
            'uom' => $request->uom,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bill of materials line added',
            'data' => ['bom_item' => $bomItem->load(['sku', 'material'])],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $bomItem = BomItem::whereNull('deleted_at')->find($id);
        if (!$bomItem) {
            return response()->json(['success' => false, 'message' => 'Bill of materials line not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'qty_per_unit' => 'required|numeric|min:0.0001',
            'uom' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $bomItem->update([
            'qty_per_unit' => $request->qty_per_unit,
            'uom' => $request->uom,
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bill of materials line updated',
            'data' => ['bom_item' => $bomItem->load(['sku', 'material'])],
        ]);
    }

    public function destroy($id)
    {
        $bomItem = BomItem::whereNull('deleted_at')->find($id);
        if (!$bomItem) {
            return response()->json(['success' => false, 'message' => 'Bill of materials line not found'], 404);
        }

        $bomItem->update(['deleted_at' => now(), 'updated_by' => Auth::id()]);

        return response()->json(['success' => true, 'message' => 'Bill of materials line removed']);
    }
}

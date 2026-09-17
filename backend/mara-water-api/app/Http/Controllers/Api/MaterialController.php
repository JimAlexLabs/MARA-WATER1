<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * Raw materials (bottles/preforms by size, bailing papers, etc.) --
 * Phase 8. The `materials` table and its stock_items/stock_moves rows
 * already existed from an earlier build, but nothing let anyone create,
 * edit, or browse a material through the API. Without this there was no
 * way to even name a raw material for the BOM (Phase 8's auto-deduction)
 * or a GRN (goods received) stock move to point at.
 */
class MaterialController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Material::with('supplier')->whereNull('deleted_at');

            if ($request->filled('category')) {
                $query->where('category', $request->category);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }

            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->get('limit', 50);
            $materials = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $materials->items(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve materials',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:20|unique:materials,code',
            'name' => 'required|string|max:200',
            'category' => 'required|string|max:50',
            'uom' => 'required|string|max:10',
            'unit_cost' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'is_consumable' => 'nullable|boolean',
            'min_level' => 'nullable|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $material = Material::create([
            'code' => $request->code,
            'name' => $request->name,
            'category' => $request->category,
            'uom' => $request->uom,
            'unit_cost' => $request->unit_cost ?? 0,
            'expiry_date' => $request->expiry_date,
            'is_consumable' => $request->is_consumable ?? true,
            'min_level' => $request->min_level ?? 0,
            'lead_time_days' => $request->lead_time_days ?? 0,
            'supplier_id' => $request->supplier_id,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Material created successfully',
            'data' => ['material' => $material->load('supplier')],
        ], 201);
    }

    public function show($id)
    {
        $material = Material::with('supplier')->whereNull('deleted_at')->find($id);
        if (!$material) {
            return response()->json(['success' => false, 'message' => 'Material not found'], 404);
        }

        return response()->json(['success' => true, 'data' => ['material' => $material]]);
    }

    public function update(Request $request, $id)
    {
        $material = Material::whereNull('deleted_at')->find($id);
        if (!$material) {
            return response()->json(['success' => false, 'message' => 'Material not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|required|string|max:20|unique:materials,code,' . $id,
            'name' => 'sometimes|required|string|max:200',
            'category' => 'sometimes|required|string|max:50',
            'uom' => 'sometimes|required|string|max:10',
            'unit_cost' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'is_consumable' => 'nullable|boolean',
            'min_level' => 'nullable|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $material->update(array_merge(
            $request->only(['code', 'name', 'category', 'uom', 'unit_cost', 'expiry_date', 'is_consumable', 'min_level', 'lead_time_days', 'supplier_id']),
            ['updated_by' => Auth::id()]
        ));

        return response()->json([
            'success' => true,
            'message' => 'Material updated successfully',
            'data' => ['material' => $material->load('supplier')],
        ]);
    }

    public function destroy($id)
    {
        $material = Material::whereNull('deleted_at')->find($id);
        if (!$material) {
            return response()->json(['success' => false, 'message' => 'Material not found'], 404);
        }

        // Round 2 Phase 3: deleted_at isn't in $fillable (correctly), so
        // mass-assigning it here silently did nothing -- see WaterTestController.
        $material->update(['updated_by' => Auth::id()]);
        $material->delete();

        return response()->json(['success' => true, 'message' => 'Material removed successfully']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 8: skus had no controller at all -- every SKU in the catalog
 * could only ever be created by direct DB seeding. This adds just enough
 * to browse the catalog and adjust the new brand/reorder_threshold
 * fields; full create/deactivate management can follow later if the
 * catalog needs to grow beyond what's seeded.
 */
class SkuController extends Controller
{
    public function index(Request $request)
    {
        $query = Sku::query();

        if ($request->filled('brand')) {
            $query->where('brand', $request->brand);
        }
        if ($request->filled('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('brand')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $sku = Sku::find($id);
        if (!$sku) {
            return response()->json(['success' => false, 'message' => 'SKU not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'brand' => 'nullable|string|max:50',
            'reorder_threshold' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $sku->update(array_merge(
            $request->only(['brand', 'reorder_threshold', 'active']),
            ['updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'SKU updated successfully', 'data' => ['sku' => $sku]]);
    }
}

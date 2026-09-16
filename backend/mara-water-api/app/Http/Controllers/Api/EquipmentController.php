<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

/**
 * Equipment & machinery log, and test equipment (Phase 10). Condition,
 * last/next service, and calibration status don't fit the materials/
 * BOM model (Phase 8) -- this is genuinely new, standing state rather
 * than a filtered view of something that already exists.
 */
class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        $query = EquipmentItem::whereNull('deleted_at');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('category')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category' => 'required|in:equipment,test_equipment',
            'name' => 'required|string|max:200',
            'unit' => 'nullable|string|max:20',
            'qty_on_hand' => 'nullable|integer|min:0',
            'minimum_required' => 'nullable|integer|min:0',
            'condition' => 'nullable|in:good,fair,poor,broken',
            'last_service_date' => 'nullable|date',
            'next_service_due' => 'nullable|date',
            'calibration_status' => 'nullable|in:calibrated,due,not_calibrated',
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $item = EquipmentItem::create(array_merge(
            $request->only(['category', 'name', 'unit', 'qty_on_hand', 'minimum_required', 'condition', 'last_service_date', 'next_service_due', 'calibration_status', 'notes']),
            ['unit' => $request->unit ?? 'PCS', 'created_by' => Auth::id(), 'updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'Equipment item added', 'data' => ['item' => $item]], 201);
    }

    public function update(Request $request, $id)
    {
        $item = EquipmentItem::whereNull('deleted_at')->find($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Equipment item not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'sometimes|required|in:equipment,test_equipment',
            'name' => 'sometimes|required|string|max:200',
            'unit' => 'nullable|string|max:20',
            'qty_on_hand' => 'nullable|integer|min:0',
            'minimum_required' => 'nullable|integer|min:0',
            'condition' => 'nullable|in:good,fair,poor,broken',
            'last_service_date' => 'nullable|date',
            'next_service_due' => 'nullable|date',
            'calibration_status' => 'nullable|in:calibrated,due,not_calibrated',
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $item->update(array_merge(
            $request->only(['category', 'name', 'unit', 'qty_on_hand', 'minimum_required', 'condition', 'last_service_date', 'next_service_due', 'calibration_status', 'notes']),
            ['updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'Equipment item updated', 'data' => ['item' => $item]]);
    }

    public function destroy($id)
    {
        $item = EquipmentItem::whereNull('deleted_at')->find($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Equipment item not found'], 404);
        }

        $item->update(['deleted_at' => now(), 'updated_by' => Auth::id()]);

        return response()->json(['success' => true, 'message' => 'Equipment item removed']);
    }
}

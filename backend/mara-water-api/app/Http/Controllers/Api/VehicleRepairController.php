<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleRepair;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ops brief §2.5 / §6.5: major/minor vehicle repair costs.
 * Manager/Director log; drivers see costs for their assigned vehicle.
 */
class VehicleRepairController extends Controller
{
    public function index(Request $request)
    {
        $query = VehicleRepair::with('vehicle')->whereNull('deleted_at');

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        if ($request->filled('from')) {
            $query->where('repair_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('repair_date', '<=', $request->to);
        }

        $rows = $query->orderByDesc('repair_date')->paginate($request->get('limit', 50));

        return response()->json([
            'success' => true,
            'data' => $rows->items(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|uuid|exists:vehicles,id',
            'repair_date' => 'required|date',
            'category' => 'required|in:major,minor',
            'description' => 'required|string|max:500',
            'cost' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = VehicleRepair::create([
            ...$validator->validated(),
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Repair logged',
            'data' => $row->load('vehicle'),
        ], 201);
    }

    public function destroy(string $id)
    {
        $row = VehicleRepair::findOrFail($id);
        $row->delete();

        return response()->json(['success' => true, 'message' => 'Repair deleted']);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = VehicleRepair::with('vehicle')->whereNull('deleted_at')->orderByDesc('repair_date');
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }
        $rows = $query->get();

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Vehicle Repairs');
        foreach (['Date', 'Vehicle', 'Category', 'Description', 'Cost (KES)'] as $i => $h) {
            $ws->setCellValue([$i + 1, 1], $h);
        }
        $r = 2;
        foreach ($rows as $row) {
            $ws->setCellValue([1, $r], optional($row->repair_date)->toDateString());
            $ws->setCellValue([2, $r], $row->vehicle->reg_no ?? '');
            $ws->setCellValue([3, $r], $row->category);
            $ws->setCellValue([4, $r], $row->description);
            $ws->setCellValue([5, $r], (float) $row->cost);
            $r++;
        }

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="vehicle-repairs.xlsx"',
        ]);
    }
}

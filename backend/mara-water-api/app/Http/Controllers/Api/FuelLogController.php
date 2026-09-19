<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FuelLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 4 Phase 8: Fuel Logs as its own standalone entity, entered only
 * by Manager/Director (route-gated tier:manager,director) -- no driver-
 * facing entry point anywhere, and End Trip no longer captures fuel at
 * all. The fuel_logs table itself already existed (this is what
 * DriverTripController::end() used to write to from the trip form);
 * this controller is what's new -- its own management API/page.
 */
class FuelLogController extends Controller
{
    public function index(Request $request)
    {
        $query = FuelLog::with('vehicle');

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->vehicle_id);
        }

        // Daily/weekly/monthly/annual filters -- a plain date range
        // covers all four; the frontend just picks the range.
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $logs = $query->orderByDesc('date')->paginate($request->get('limit', 20));

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total(),
                'total_spent' => round((float) (clone $query)->getQuery()->reorder()->sum('cost') ?: 0, 2),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|exists:vehicles,id',
            'date' => 'required|date',
            'cost' => 'required|numeric|min:0.01',
            'liters' => 'required|numeric|min:0.01',
            'odometer' => 'nullable|integer|min:0',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $log = FuelLog::create([
            'vehicle_id' => $request->vehicle_id,
            'date' => $request->date,
            'cost' => $request->cost,
            'liters' => $request->liters,
            'odometer' => $request->odometer,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'message' => 'Fuel log recorded', 'data' => $log->load('vehicle')], 201);
    }

    public function destroy($id)
    {
        $log = FuelLog::findOrFail($id);
        $log->delete();
        return response()->json(['success' => true, 'message' => 'Fuel log deleted']);
    }

    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $rows = FuelLog::with('vehicle')
            ->whereDate('date', '>=', $request->date_from)
            ->whereDate('date', '<=', $request->date_to)
            ->orderBy('date')
            ->get();

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Fuel Logs');

        $title = ['font' => ['bold' => true, 'size' => 14]];
        $bold = ['font' => ['bold' => true]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $ws->setCellValue('A1', 'FUEL LOG ' . $request->date_from . ' to ' . $request->date_to);
        $ws->getStyle('A1')->applyFromArray($title);

        $headers = ['Date', 'Vehicle', 'Amount Paid (KES)', 'Liters', 'Price/Liter (KES)', 'Odometer'];
        $row = 3;
        $col = 'A';
        foreach ($headers as $h) {
            $ws->setCellValue("{$col}{$row}", $h);
            $ws->getStyle("{$col}{$row}")->applyFromArray($headerFill + $thin);
            $col++;
        }
        $row++;

        $totalPaid = 0;
        $totalLiters = 0;
        foreach ($rows as $log) {
            $cells = [
                $log->date->toDateString(), $log->vehicle->reg_no ?? '—',
                $log->cost, $log->liters, $log->price_per_liter, $log->odometer,
            ];
            $col = 'A';
            foreach ($cells as $value) {
                $ws->setCellValue("{$col}{$row}", $value);
                $ws->getStyle("{$col}{$row}")->applyFromArray($thin);
                $col++;
            }
            $totalPaid += (float) $log->cost;
            $totalLiters += (float) $log->liters;
            $row++;
        }

        $ws->setCellValue("A{$row}", 'TOTAL');
        $ws->getStyle("A{$row}")->applyFromArray($bold);
        $ws->setCellValue("C{$row}", round($totalPaid, 2));
        $ws->setCellValue("D{$row}", round($totalLiters, 2));
        $ws->getStyle("C{$row}:D{$row}")->applyFromArray($bold);

        foreach (range('A', 'F') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "fuel-log-{$request->date_from}-to-{$request->date_to}.xlsx";
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

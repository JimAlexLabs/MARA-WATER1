<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discrepancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 4 Phase 6: the dedicated Discrepancies page -- Manager/Director
 * only (route-gated tier:manager,director; never reachable from the
 * Driver dashboard). Rows are created by DriverTripController::end()
 * (cash/stock) and (mileage) -- this controller only reads/resolves/
 * exports them.
 */
class DiscrepancyController extends Controller
{
    public function index(Request $request)
    {
        $query = Discrepancy::with(['trip', 'vehicle', 'driver']);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $items = $query->orderByDesc('date')->orderByDesc('created_at')->paginate($request->get('limit', 20));

        // Rollups for the period in view (or all time if no date filter) --
        // "support daily/weekly/monthly rollups."
        $rollupQuery = Discrepancy::query();
        if ($request->filled('date_from')) {
            $rollupQuery->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $rollupQuery->whereDate('date', '<=', $request->date_to);
        }
        $byCategory = (clone $rollupQuery)->selectRaw('category, COUNT(*) as count, SUM(amount) as total')->groupBy('category')->get()->keyBy('category');
        $byDay = (clone $rollupQuery)->selectRaw('date, COUNT(*) as count')->groupBy('date')->orderBy('date')->get();

        return response()->json([
            'success' => true,
            'data' => $items->items(),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
            'rollups' => ['by_category' => $byCategory, 'by_day' => $byDay],
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:open,reviewed,resolved',
            'resolution_note' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $discrepancy = Discrepancy::findOrFail($id);
        $discrepancy->update([
            'status' => $request->status,
            'resolution_note' => $request->resolution_note ?? $discrepancy->resolution_note,
            'resolved_by' => $request->status === 'resolved' ? Auth::id() : $discrepancy->resolved_by,
            'resolved_at' => $request->status === 'resolved' ? now() : $discrepancy->resolved_at,
            'updated_by' => Auth::id(),
        ]);

        return response()->json(['success' => true, 'data' => $discrepancy->fresh(['trip', 'vehicle', 'driver'])]);
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

        $rows = Discrepancy::with(['vehicle', 'driver'])
            ->whereDate('date', '>=', $request->date_from)
            ->whereDate('date', '<=', $request->date_to)
            ->orderBy('date')
            ->get();

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Discrepancies');

        $bold = ['font' => ['bold' => true]];
        $title = ['font' => ['bold' => true, 'size' => 14]];
        $headerFill = ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']], 'font' => ['bold' => true]];
        $thin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $ws->setCellValue('A1', 'DISCREPANCY LOG ' . $request->date_from . ' to ' . $request->date_to);
        $ws->getStyle('A1')->applyFromArray($title);

        $headers = ['Date', 'Driver', 'Vehicle', 'Category', 'Amount/Qty Off', 'Description', 'Status', 'Resolution Note'];
        $row = 3;
        $col = 'A';
        foreach ($headers as $h) {
            $ws->setCellValue("{$col}{$row}", $h);
            $ws->getStyle("{$col}{$row}")->applyFromArray($headerFill + $thin);
            $col++;
        }
        $row++;

        foreach ($rows as $d) {
            $cells = [
                $d->date->toDateString(),
                $d->driver->full_name ?? '—',
                $d->vehicle->reg_no ?? '—',
                ucfirst($d->category),
                $d->amount,
                $d->description,
                ucfirst($d->status),
                $d->resolution_note ?? '',
            ];
            $col = 'A';
            foreach ($cells as $value) {
                $ws->setCellValue("{$col}{$row}", $value);
                $ws->getStyle("{$col}{$row}")->applyFromArray($thin);
                $col++;
            }
            $row++;
        }

        foreach (range('A', 'H') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "discrepancy-log-{$request->date_from}-to-{$request->date_to}.xlsx";
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

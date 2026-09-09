<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\PackagingRun;
use App\Models\Batch;
use App\Models\Sku;

class PackagingRunController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = PackagingRun::with(['batch', 'sku', 'runBy']);

            // Filtering
            if ($request->filled('batch_id')) {
                $query->where('batch_id', $request->batch_id);
            }
            if ($request->filled('sku_id')) {
                $query->where('sku_id', $request->sku_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('date_from')) {
                $query->whereDate('run_start', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('run_start', '<=', $request->date_to);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'run_start');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $packagingRuns = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $packagingRuns->items()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve packaging runs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'batch_id' => 'required|exists:batches,id',
                'sku_id' => 'required|exists:skus,id',
                'run_start' => 'required|date',
                'run_end' => 'nullable|date|after:run_start',
                'good_qty' => 'required|integer|min:0',
                'scrap_qty' => 'required|integer|min:0',
                'downtime_minutes' => 'nullable|integer|min:0',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate batch status
            $batch = Batch::find($request->batch_id);
            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch not found'
                ], 404);
            }

            if ($batch->status !== 'in_progress') {
                return response()->json([
                    'success' => false,
                    'message' => 'Packaging run can only be created for batches in progress'
                ], 422);
            }

            // Calculate yield percentage
            $totalQty = $request->good_qty + $request->scrap_qty;
            $yieldPercentage = $totalQty > 0 ? ($request->good_qty / $totalQty) * 100 : 0;

            $packagingRun = PackagingRun::create([
                'batch_id' => $request->batch_id,
                'sku_id' => $request->sku_id,
                'run_start' => $request->run_start,
                'run_end' => $request->run_end,
                'good_qty' => $request->good_qty,
                'scrap_qty' => $request->scrap_qty,
                'downtime_minutes' => $request->downtime_minutes,
                'notes' => $request->notes,
                'run_by' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Update batch if run is completed
            if ($request->run_end) {
                $this->updateBatchStatus($batch, $request->good_qty);
            }

            return response()->json([
                'success' => true,
                'message' => 'Packaging run created successfully',
                'data' => [
                    'packaging_run' => $packagingRun->load(['batch', 'sku', 'runBy']),
                    'yield_percentage' => round($yieldPercentage, 2)
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create packaging run',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $packagingRun = PackagingRun::with(['batch', 'sku', 'runBy'])->find($id);

            if (!$packagingRun) {
                return response()->json([
                    'success' => false,
                    'message' => 'Packaging run not found'
                ], 404);
            }

            // Calculate yield percentage
            $totalQty = $packagingRun->good_qty + $packagingRun->scrap_qty;
            $yieldPercentage = $totalQty > 0 ? ($packagingRun->good_qty / $totalQty) * 100 : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'packaging_run' => $packagingRun,
                    'yield_percentage' => round($yieldPercentage, 2)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve packaging run',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $packagingRun = PackagingRun::find($id);

            if (!$packagingRun) {
                return response()->json([
                    'success' => false,
                    'message' => 'Packaging run not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'run_end' => 'nullable|date|after:run_start',
                'good_qty' => 'sometimes|required|integer|min:0',
                'scrap_qty' => 'sometimes|required|integer|min:0',
                'downtime_minutes' => 'nullable|integer|min:0',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $packagingRun->update([
                'run_end' => $request->run_end,
                'good_qty' => $request->good_qty ?? $packagingRun->good_qty,
                'scrap_qty' => $request->scrap_qty ?? $packagingRun->scrap_qty,
                'downtime_minutes' => $request->downtime_minutes,
                'notes' => $request->notes,
                'updated_by' => Auth::id(),
            ]);

            // Update batch if run is completed
            if ($request->run_end && !$packagingRun->run_end) {
                $this->updateBatchStatus($packagingRun->batch, $packagingRun->good_qty);
            }

            return response()->json([
                'success' => true,
                'message' => 'Packaging run updated successfully',
                'data' => [
                    'packaging_run' => $packagingRun->load(['batch', 'sku', 'runBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update packaging run',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $packagingRun = PackagingRun::find($id);

            if (!$packagingRun) {
                return response()->json([
                    'success' => false,
                    'message' => 'Packaging run not found'
                ], 404);
            }

            // Check if run is completed
            if ($packagingRun->run_end) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete completed packaging run'
                ], 422);
            }

            $packagingRun->delete();

            return response()->json([
                'success' => true,
                'message' => 'Packaging run deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete packaging run',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function complete(Request $request, $id)
    {
        try {
            $packagingRun = PackagingRun::find($id);

            if (!$packagingRun) {
                return response()->json([
                    'success' => false,
                    'message' => 'Packaging run not found'
                ], 404);
            }

            if ($packagingRun->run_end) {
                return response()->json([
                    'success' => false,
                    'message' => 'Packaging run is already completed'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'good_qty' => 'required|integer|min:0',
                'scrap_qty' => 'required|integer|min:0',
                'downtime_minutes' => 'nullable|integer|min:0',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $packagingRun->update([
                'run_end' => now(),
                'good_qty' => $request->good_qty,
                'scrap_qty' => $request->scrap_qty,
                'downtime_minutes' => $request->downtime_minutes,
                'notes' => $request->notes,
                'updated_by' => Auth::id(),
            ]);

            // Update batch status
            $this->updateBatchStatus($packagingRun->batch, $request->good_qty);

            // Calculate yield percentage
            $totalQty = $request->good_qty + $request->scrap_qty;
            $yieldPercentage = $totalQty > 0 ? ($request->good_qty / $totalQty) * 100 : 0;

            return response()->json([
                'success' => true,
                'message' => 'Packaging run completed successfully',
                'data' => [
                    'packaging_run' => $packagingRun->load(['batch', 'sku', 'runBy']),
                    'yield_percentage' => round($yieldPercentage, 2)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete packaging run',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function statistics(Request $request)
    {
        try {
            $query = PackagingRun::query();

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('run_start', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('run_start', '<=', $request->date_to);
            }

            // Filter by SKU
            if ($request->filled('sku_id')) {
                $query->where('sku_id', $request->sku_id);
            }

            $stats = $query->selectRaw('
                COUNT(*) as total_runs,
                SUM(good_qty) as total_good_qty,
                SUM(scrap_qty) as total_scrap_qty,
                SUM(downtime_minutes) as total_downtime_minutes,
                AVG(good_qty) as avg_good_qty,
                AVG(scrap_qty) as avg_scrap_qty,
                AVG(downtime_minutes) as avg_downtime_minutes
            ')->first();

            // Calculate yield percentage
            $totalQty = $stats->total_good_qty + $stats->total_scrap_qty;
            $overallYield = $totalQty > 0 ? ($stats->total_good_qty / $totalQty) * 100 : 0;

            // Get runs by status
            $runsByStatus = $query->selectRaw('
                CASE 
                    WHEN run_end IS NULL THEN "in_progress"
                    ELSE "completed"
                END as status,
                COUNT(*) as count
            ')->groupBy('status')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => [
                        'total_runs' => $stats->total_runs ?? 0,
                        'total_good_qty' => $stats->total_good_qty ?? 0,
                        'total_scrap_qty' => $stats->total_scrap_qty ?? 0,
                        'total_downtime_minutes' => $stats->total_downtime_minutes ?? 0,
                        'avg_good_qty' => round($stats->avg_good_qty ?? 0, 2),
                        'avg_scrap_qty' => round($stats->avg_scrap_qty ?? 0, 2),
                        'avg_downtime_minutes' => round($stats->avg_downtime_minutes ?? 0, 2),
                        'overall_yield_percentage' => round($overallYield, 2)
                    ],
                    'runs_by_status' => $runsByStatus
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

    public function byBatch(Request $request, $batchId)
    {
        try {
            $query = PackagingRun::with(['sku', 'runBy'])
                ->where('batch_id', $batchId);

            // Sorting
            $sortBy = $request->get('sort_by', 'run_start');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $packagingRuns = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'packaging_runs' => $packagingRuns->items(),
                    'pagination' => [
                        'current_page' => $packagingRuns->currentPage(),
                        'per_page' => $packagingRuns->perPage(),
                        'total' => $packagingRuns->total(),
                        'last_page' => $packagingRuns->lastPage(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve packaging runs for batch',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function updateBatchStatus($batch, $goodQty)
    {
        // Update batch with actual produced quantity
        $batch->update([
            'actual_qty' => $goodQty,
            'status' => 'closed',
            'closed_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
    }
}

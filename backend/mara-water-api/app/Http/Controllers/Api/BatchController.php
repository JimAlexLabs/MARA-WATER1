<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BatchController extends Controller
{
    /**
     * Display a listing of batches
     */
    public function index(Request $request)
    {
        try {
            $query = Batch::with(['sku', 'openedBy', 'closedBy'])
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('sku_id')) {
                $query->where('sku_id', $request->sku_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('manufacture_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('manufacture_date', '<=', $request->date_to);
            }

            if ($request->filled('opened_by')) {
                $query->where('opened_by', $request->opened_by);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'manufacture_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $batches = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $batches->items()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batches',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created batch
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'sku_id' => 'required|exists:skus,id',
                'manufacture_date' => 'required|date',
                'expiry_date' => 'required|date|after:manufacture_date',
                'planned_qty' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Generate unique batch code
            $batchCode = $this->generateBatchCode($request->sku_id, $request->manufacture_date);

            $batch = Batch::create([
                'code' => $batchCode,
                'sku_id' => $request->sku_id,
                'manufacture_date' => $request->manufacture_date,
                'expiry_date' => $request->expiry_date,
                'planned_qty' => $request->planned_qty,
                'status' => 'open',
                'opened_by' => Auth::id(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $batch->load(['sku', 'openedBy']);

            return response()->json([
                'success' => true,
                'message' => 'Batch created successfully',
                'data' => [
                    'batch' => $batch
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create batch',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified batch
     */
    public function show($id)
    {
        try {
            $batch = Batch::with(['sku', 'openedBy', 'closedBy'])
                ->whereNull('deleted_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'batch' => $batch
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified batch
     */
    public function update(Request $request, $id)
    {
        try {
            $batch = Batch::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'planned_qty' => 'nullable|integer|min:1',
                'expiry_date' => 'nullable|date|after:manufacture_date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Only allow updates if batch is still open
            if ($batch->status !== 'open') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update batch that is not in open status'
                ], 422);
            }

            $batch->update([
                'planned_qty' => $request->planned_qty ?? $batch->planned_qty,
                'expiry_date' => $request->expiry_date ?? $batch->expiry_date,
                'updated_by' => Auth::id(),
            ]);

            $batch->load(['sku', 'openedBy', 'closedBy']);

            return response()->json([
                'success' => true,
                'message' => 'Batch updated successfully',
                'data' => [
                    'batch' => $batch
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update batch',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified batch
     */
    public function destroy($id)
    {
        try {
            $batch = Batch::whereNull('deleted_at')->findOrFail($id);
            
            // Only allow deletion if batch is still open
            if ($batch->status !== 'open') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete batch that is not in open status'
                ], 422);
            }

            // Soft delete
            $batch->update([
                'deleted_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete batch',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update batch status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $batch = Batch::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:open,in_progress,closed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $newStatus = $request->status;
            $currentStatus = $batch->status;

            // Validate status transitions
            if (!$this->isValidStatusTransition($currentStatus, $newStatus)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status transition from ' . $currentStatus . ' to ' . $newStatus
                ], 422);
            }

            $updateData = [
                'status' => $newStatus,
                'updated_by' => Auth::id(),
            ];

            // Set closed_by when closing batch
            if ($newStatus === 'closed') {
                $updateData['closed_by'] = Auth::id();
            }

            $batch->update($updateData);

            $batch->load(['sku', 'openedBy', 'closedBy']);

            return response()->json([
                'success' => true,
                'message' => 'Batch status updated successfully',
                'data' => [
                    'batch' => $batch
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update batch status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get batch statistics
     */
    public function statistics(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());

            $stats = Batch::whereNull('deleted_at')
                ->whereBetween('manufacture_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_batches,
                    COUNT(CASE WHEN status = "open" THEN 1 END) as open_batches,
                    COUNT(CASE WHEN status = "in_progress" THEN 1 END) as in_progress_batches,
                    COUNT(CASE WHEN status = "closed" THEN 1 END) as closed_batches,
                    SUM(planned_qty) as total_planned_qty,
                    SUM(CASE WHEN status = "closed" THEN planned_qty ELSE 0 END) as completed_qty
                ')
                ->first();

            $efficiencyRate = $stats->total_planned_qty > 0 
                ? round(($stats->completed_qty / $stats->total_planned_qty) * 100, 2) 
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ],
                    'statistics' => [
                        'total_batches' => $stats->total_batches,
                        'open_batches' => $stats->open_batches,
                        'in_progress_batches' => $stats->in_progress_batches,
                        'closed_batches' => $stats->closed_batches,
                        'total_planned_qty' => $stats->total_planned_qty,
                        'completed_qty' => $stats->completed_qty,
                        'efficiency_rate' => $efficiencyRate,
                    ]
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

    /**
     * Get batches by SKU
     */
    public function bySku(Request $request, $skuId)
    {
        try {
            $query = Batch::with(['sku', 'openedBy', 'closedBy'])
                ->where('sku_id', $skuId)
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('manufacture_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('manufacture_date', '<=', $request->date_to);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'manufacture_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $batches = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'batches' => $batches->items(),
                    'pagination' => [
                        'current_page' => $batches->currentPage(),
                        'per_page' => $batches->perPage(),
                        'total' => $batches->total(),
                        'last_page' => $batches->lastPage(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve batches for SKU',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique batch code
     */
    private function generateBatchCode($skuId, $manufactureDate)
    {
        $sku = Sku::find($skuId);
        $skuCode = $sku ? $sku->code : 'SKU';
        $date = date('Ymd', strtotime($manufactureDate));
        
        // Get count of batches for this SKU on this date
        $count = Batch::where('sku_id', $skuId)
            ->whereDate('manufacture_date', $manufactureDate)
            ->count();

        $sequence = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
        
        return "BATCH-{$skuCode}-{$date}-{$sequence}";
    }

    /**
     * Validate status transitions
     */
    private function isValidStatusTransition($currentStatus, $newStatus)
    {
        $validTransitions = [
            'open' => ['in_progress', 'closed'],
            'in_progress' => ['closed'],
            'closed' => [], // Cannot transition from closed
        ];

        return in_array($newStatus, $validTransitions[$currentStatus] ?? []);
    }
}

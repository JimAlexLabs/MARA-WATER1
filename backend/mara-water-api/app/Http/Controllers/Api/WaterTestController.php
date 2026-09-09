<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WaterTest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WaterTestController extends Controller
{
    /**
     * Display a listing of water tests
     */
    public function index(Request $request)
    {
        try {
            $query = WaterTest::with(['recordedBy', 'verifiedBy', 'warehouse'])
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('test_type')) {
                $query->where('test_type', $request->test_type);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('recorded_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('recorded_at', '<=', $request->date_to);
            }

            if ($request->filled('recorded_by')) {
                $query->where('recorded_by', $request->recorded_by);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'recorded_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $waterTests = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $waterTests->items()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve water tests',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created water test
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'test_type' => 'required|in:baseline,random,retest',
                'recorded_at' => 'required|date',
                'ph' => 'nullable|numeric|between:0,14',
                'tds' => 'nullable|numeric|min:0',
                'chlorine' => 'nullable|numeric|min:0',
                'unit_notes' => 'nullable|string|max:1000',
                'location_text' => 'nullable|string|max:255',
                'warehouse_id' => 'nullable|exists:warehouses,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate water quality thresholds
            $validationErrors = $this->validateWaterQualityThresholds($request);
            if (!empty($validationErrors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Water quality thresholds exceeded',
                    'errors' => $validationErrors
                ], 422);
            }

            $waterTest = WaterTest::create([
                'test_type' => $request->test_type,
                'recorded_at' => $request->recorded_at,
                'ph' => $request->ph,
                'tds' => $request->tds,
                'chlorine' => $request->chlorine,
                'unit_notes' => $request->unit_notes,
                'location_text' => $request->location_text,
                'warehouse_id' => $request->warehouse_id,
                'recorded_by' => Auth::id(),
                'status' => 'pending',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $waterTest->load(['recordedBy', 'warehouse']);

            return response()->json([
                'success' => true,
                'message' => 'Water test created successfully',
                'data' => [
                    'water_test' => $waterTest
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create water test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified water test
     */
    public function show($id)
    {
        try {
            $waterTest = WaterTest::with(['recordedBy', 'verifiedBy', 'warehouse'])
                ->whereNull('deleted_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'water_test' => $waterTest
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Water test not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified water test
     */
    public function update(Request $request, $id)
    {
        try {
            $waterTest = WaterTest::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'ph' => 'nullable|numeric|between:0,14',
                'tds' => 'nullable|numeric|min:0',
                'chlorine' => 'nullable|numeric|min:0',
                'unit_notes' => 'nullable|string|max:1000',
                'location_text' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate water quality thresholds if values are being updated
            if ($request->has('ph') || $request->has('tds') || $request->has('chlorine')) {
                $validationErrors = $this->validateWaterQualityThresholds($request);
                if (!empty($validationErrors)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Water quality thresholds exceeded',
                        'errors' => $validationErrors
                    ], 422);
                }
            }

            $waterTest->update([
                'ph' => $request->ph ?? $waterTest->ph,
                'tds' => $request->tds ?? $waterTest->tds,
                'chlorine' => $request->chlorine ?? $waterTest->chlorine,
                'unit_notes' => $request->unit_notes ?? $waterTest->unit_notes,
                'location_text' => $request->location_text ?? $waterTest->location_text,
                'updated_by' => Auth::id(),
            ]);

            $waterTest->load(['recordedBy', 'verifiedBy', 'warehouse']);

            return response()->json([
                'success' => true,
                'message' => 'Water test updated successfully',
                'data' => [
                    'water_test' => $waterTest
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update water test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified water test
     */
    public function destroy($id)
    {
        try {
            $waterTest = WaterTest::whereNull('deleted_at')->findOrFail($id);
            
            // Soft delete
            $waterTest->update([
                'deleted_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Water test deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete water test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify water test (RIC role only)
     */
    public function verify(Request $request, $id)
    {
        try {
            $waterTest = WaterTest::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:pass,fail',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if user has RIC role
            $user = Auth::user();
            if ($user->role->code !== 'RIC') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only RIC technicians can verify water tests'
                ], 403);
            }

            $waterTest->update([
                'status' => $request->status,
                'ric_verified_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $waterTest->load(['recordedBy', 'verifiedBy', 'warehouse']);

            return response()->json([
                'success' => true,
                'message' => 'Water test verified successfully',
                'data' => [
                    'water_test' => $waterTest
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify water test',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get water test statistics
     */
    public function statistics(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());

            $stats = WaterTest::whereNull('deleted_at')
                ->whereBetween('recorded_at', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_tests,
                    COUNT(CASE WHEN status = "pass" THEN 1 END) as passed_tests,
                    COUNT(CASE WHEN status = "fail" THEN 1 END) as failed_tests,
                    COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_tests,
                    AVG(ph) as avg_ph,
                    AVG(tds) as avg_tds,
                    AVG(chlorine) as avg_chlorine
                ')
                ->first();

            $complianceRate = $stats->total_tests > 0 
                ? round(($stats->passed_tests / $stats->total_tests) * 100, 2) 
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ],
                    'statistics' => [
                        'total_tests' => $stats->total_tests,
                        'passed_tests' => $stats->passed_tests,
                        'failed_tests' => $stats->failed_tests,
                        'pending_tests' => $stats->pending_tests,
                        'compliance_rate' => $complianceRate,
                        'average_ph' => round($stats->avg_ph, 2),
                        'average_tds' => round($stats->avg_tds, 2),
                        'average_chlorine' => round($stats->avg_chlorine, 3),
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
     * Validate water quality thresholds
     */
    private function validateWaterQualityThresholds(Request $request)
    {
        $errors = [];

        // pH validation (acceptable range: 6.5 - 8.5)
        if ($request->has('ph')) {
            $ph = $request->ph;
            if ($ph < 6.5 || $ph > 8.5) {
                $errors['ph'] = ['pH value must be between 6.5 and 8.5'];
            }
        }

        // TDS validation (max 500 ppm)
        if ($request->has('tds')) {
            $tds = $request->tds;
            if ($tds > 500) {
                $errors['tds'] = ['TDS value must not exceed 500 ppm'];
            }
        }

        // Chlorine validation (max 2.0 ppm)
        if ($request->has('chlorine')) {
            $chlorine = $request->chlorine;
            if ($chlorine > 2.0) {
                $errors['chlorine'] = ['Chlorine value must not exceed 2.0 ppm'];
            }
        }

        return $errors;
    }
}

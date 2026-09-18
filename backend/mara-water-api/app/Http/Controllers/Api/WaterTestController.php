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

            // Round 3 Phase 11: a water test that fails a threshold is
            // exactly the record a QA system most needs to keep -- the old
            // behavior rejected it outright (422) before it could ever be
            // saved, which is why every test that *did* save was
            // structurally guaranteed to be a pass, yet the status column
            // was still hardcoded to 'pending' and nothing ever moved it
            // to 'pass'. Fix: compute pass/fail from the same values in
            // this same write (no separate, never-called step), and save
            // either outcome -- a fail becomes a real, visible record
            // instead of a silently-blocked one.
            $statusResult = $this->determineTestStatus($request);

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
                'status' => $statusResult['status'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $waterTest->load(['recordedBy', 'warehouse']);

            return response()->json([
                'success' => true,
                'message' => $statusResult['status'] === 'fail'
                    ? 'Water test recorded -- FAILED threshold check, see failed_checks'
                    : 'Water test created successfully',
                'data' => [
                    'water_test' => $waterTest,
                    'failed_checks' => $statusResult['failed_checks'],
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

            // Round 3 Phase 11: recompute status from the corrected values,
            // in this same write, same as store() -- but only while the
            // test hasn't been RIC-verified yet. Once verify() has signed
            // off a status, a later value edit shouldn't silently flip
            // their verdict; re-verify explicitly instead.
            $recomputeStatus = ($request->has('ph') || $request->has('tds') || $request->has('chlorine'))
                && $waterTest->ric_verified_by === null;
            $status = $waterTest->status;
            if ($recomputeStatus) {
                $merged = Request::create('', 'PUT', [
                    'ph' => $request->ph ?? $waterTest->ph,
                    'tds' => $request->tds ?? $waterTest->tds,
                    'chlorine' => $request->chlorine ?? $waterTest->chlorine,
                ]);
                $statusResult = $this->determineTestStatus($merged);
                $status = $statusResult['status'];
            }

            $waterTest->update([
                'ph' => $request->ph ?? $waterTest->ph,
                'tds' => $request->tds ?? $waterTest->tds,
                'chlorine' => $request->chlorine ?? $waterTest->chlorine,
                'unit_notes' => $request->unit_notes ?? $waterTest->unit_notes,
                'location_text' => $request->location_text ?? $waterTest->location_text,
                'status' => $status,
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
            
            // Round 2 Phase 3: `deleted_at` is deliberately not in the
            // model's $fillable (correctly -- that column shouldn't be
            // settable via a normal update), so ->update(['deleted_at' =>
            // ...]) mass-assignment silently dropped it and this delete
            // never actually happened, despite reporting success. Use
            // Eloquent's real delete(), which SoftDeletes overrides to set
            // deleted_at directly, bypassing $fillable entirely.
            $waterTest->update(['updated_by' => Auth::id()]);
            $waterTest->delete();

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
     * Round 3 Phase 11: compute pass/fail from water quality thresholds --
     * replaces the old validateWaterQualityThresholds(), which returned
     * these same checks as hard 422 validation errors and so could never
     * let a real failing test be saved at all. This version always
     * returns a status; store()/update() save it either way.
     *
     * Chlorine is free chlorine residual in mg/L (== ppm for water).
     * KEBS/WHO guidance: free chlorine residual should be maintained in
     * the 0.2-0.5 mg/L range at point of use; treatment-stage dosing may
     * run higher before residual settles -- the 2.0 mg/L ceiling here is
     * a hard upper safety bound, not the target range itself.
     */
    private function determineTestStatus(Request $request): array
    {
        $failedChecks = [];

        // pH (acceptable range: 6.5 - 8.5)
        if ($request->has('ph') && $request->ph !== null) {
            $ph = $request->ph;
            if ($ph < 6.5 || $ph > 8.5) {
                $failedChecks['ph'] = "pH {$ph} is outside the acceptable 6.5-8.5 range";
            }
        }

        // TDS (max 500 ppm)
        if ($request->has('tds') && $request->tds !== null) {
            $tds = $request->tds;
            if ($tds > 500) {
                $failedChecks['tds'] = "TDS {$tds} ppm exceeds the 500 ppm maximum";
            }
        }

        // Chlorine (free residual, mg/L -- see docblock above)
        if ($request->has('chlorine') && $request->chlorine !== null) {
            $chlorine = $request->chlorine;
            if ($chlorine > 2.0) {
                $failedChecks['chlorine'] = "Free chlorine residual {$chlorine} mg/L exceeds the 2.0 mg/L safety ceiling";
            }
        }

        return [
            'status' => empty($failedChecks) ? 'pass' : 'fail',
            'failed_checks' => $failedChecks,
        ];
    }

}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Vehicle;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Vehicle::with(['driver', 'createdBy']);

            // Filtering
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('make')) {
                $query->where('make', 'like', '%' . $request->make . '%');
            }
            if ($request->filled('model')) {
                $query->where('model', 'like', '%' . $request->model . '%');
            }
            if ($request->filled('year')) {
                $query->where('year', $request->year);
            }
            if ($request->filled('capacity_min')) {
                $query->where('capacity', '>=', $request->capacity_min);
            }
            if ($request->filled('capacity_max')) {
                $query->where('capacity', '<=', $request->capacity_max);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $vehicles = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $vehicles->items()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vehicles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'reg_no' => 'required|string|max:20|unique:vehicles,reg_no',
                'make' => 'required|string|max:100',
                'model' => 'required|string|max:100',
                'year' => 'required|integer|min:1900|max:' . (date('Y') + 1),
                'capacity' => 'required|numeric|min:0',
                'fuel_type' => 'required|string|max:50',
                'insurance_expiry' => 'required|date|after:today',
                'inspection_expiry' => 'required|date|after:today',
                'speed_gov_status' => 'required|string|max:50',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $vehicle = Vehicle::create([
                'reg_no' => strtoupper($request->reg_no),
                'make' => $request->make,
                'model' => $request->model,
                'year' => $request->year,
                'capacity' => $request->capacity,
                'fuel_type' => $request->fuel_type,
                'insurance_expiry' => $request->insurance_expiry,
                'inspection_expiry' => $request->inspection_expiry,
                'speed_gov_status' => $request->speed_gov_status,
                'notes' => $request->notes,
                'status' => 'active',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vehicle created successfully',
                'data' => [
                    'vehicle' => $vehicle->load(['driver', 'createdBy'])
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create vehicle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $vehicle = Vehicle::with(['driver', 'createdBy'])->find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'vehicle' => $vehicle
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vehicle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $vehicle = Vehicle::find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'reg_no' => 'sometimes|required|string|max:20|unique:vehicles,reg_no,' . $id,
                'make' => 'sometimes|required|string|max:100',
                'model' => 'sometimes|required|string|max:100',
                'year' => 'sometimes|required|integer|min:1900|max:' . (date('Y') + 1),
                'capacity' => 'sometimes|required|numeric|min:0',
                'fuel_type' => 'sometimes|required|string|max:50',
                'insurance_expiry' => 'sometimes|required|date',
                'inspection_expiry' => 'sometimes|required|date',
                'speed_gov_status' => 'sometimes|required|string|max:50',
                'notes' => 'nullable|string|max:1000',
                'status' => 'sometimes|required|string|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $vehicle->update([
                'reg_no' => $request->reg_no ? strtoupper($request->reg_no) : $vehicle->reg_no,
                'make' => $request->make ?? $vehicle->make,
                'model' => $request->model ?? $vehicle->model,
                'year' => $request->year ?? $vehicle->year,
                'capacity' => $request->capacity ?? $vehicle->capacity,
                'fuel_type' => $request->fuel_type ?? $vehicle->fuel_type,
                'insurance_expiry' => $request->insurance_expiry ?? $vehicle->insurance_expiry,
                'inspection_expiry' => $request->inspection_expiry ?? $vehicle->inspection_expiry,
                'speed_gov_status' => $request->speed_gov_status ?? $vehicle->speed_gov_status,
                'notes' => $request->notes,
                'status' => $request->status ?? $vehicle->status,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vehicle updated successfully',
                'data' => [
                    'vehicle' => $vehicle->load(['driver', 'createdBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update vehicle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $vehicle = Vehicle::find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found'
                ], 404);
            }

            // Check if vehicle is currently assigned to a driver
            if ($vehicle->driver_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete vehicle that is currently assigned to a driver'
                ], 422);
            }

            $vehicle->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vehicle deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete vehicle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function assignDriver(Request $request, $id)
    {
        try {
            $vehicle = Vehicle::find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'driver_id' => 'required|exists:users,id',
                'assignment_date' => 'required|date',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if driver is already assigned to another vehicle
            $existingAssignment = Vehicle::where('driver_id', $request->driver_id)
                ->where('id', '!=', $id)
                ->where('status', 'active')
                ->first();

            if ($existingAssignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver is already assigned to another vehicle'
                ], 422);
            }

            $vehicle->update([
                'driver_id' => $request->driver_id,
                'assignment_date' => $request->assignment_date,
                'notes' => $request->notes,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Driver assigned to vehicle successfully',
                'data' => [
                    'vehicle' => $vehicle->load(['driver', 'createdBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign driver to vehicle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function unassignDriver($id)
    {
        try {
            $vehicle = Vehicle::find($id);

            if (!$vehicle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle not found'
                ], 404);
            }

            if (!$vehicle->driver_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehicle is not assigned to any driver'
                ], 422);
            }

            $vehicle->update([
                'driver_id' => null,
                'assignment_date' => null,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Driver unassigned from vehicle successfully',
                'data' => [
                    'vehicle' => $vehicle->load(['driver', 'createdBy'])
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to unassign driver from vehicle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function statistics(Request $request)
    {
        try {
            $query = Vehicle::query();

            $stats = $query->selectRaw('
                COUNT(*) as total_vehicles,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_vehicles,
                SUM(CASE WHEN status = "maintenance" THEN 1 ELSE 0 END) as maintenance_vehicles,
                SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive_vehicles,
                SUM(CASE WHEN driver_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_vehicles,
                SUM(CASE WHEN driver_id IS NULL THEN 1 ELSE 0 END) as unassigned_vehicles,
                AVG(capacity) as avg_capacity
            ')->first();

            // Get vehicles by make
            $vehiclesByMake = $query->selectRaw('make, COUNT(*) as count')
                ->groupBy('make')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get();

            // Get vehicles by fuel type
            $vehiclesByFuelType = $query->selectRaw('fuel_type, COUNT(*) as count')
                ->groupBy('fuel_type')
                ->get();

            // Get vehicles with expiring documents
            $expiringInsurance = $query->where('insurance_expiry', '<=', now()->addDays(30))
                ->where('insurance_expiry', '>', now())
                ->count();

            $expiringInspection = $query->where('inspection_expiry', '<=', now()->addDays(30))
                ->where('inspection_expiry', '>', now())
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => [
                        'total_vehicles' => $stats->total_vehicles ?? 0,
                        'active_vehicles' => $stats->active_vehicles ?? 0,
                        'maintenance_vehicles' => $stats->maintenance_vehicles ?? 0,
                        'inactive_vehicles' => $stats->inactive_vehicles ?? 0,
                        'assigned_vehicles' => $stats->assigned_vehicles ?? 0,
                        'unassigned_vehicles' => $stats->unassigned_vehicles ?? 0,
                        'avg_capacity' => round($stats->avg_capacity ?? 0, 2),
                        'expiring_insurance_count' => $expiringInsurance,
                        'expiring_inspection_count' => $expiringInspection
                    ],
                    'vehicles_by_make' => $vehiclesByMake,
                    'vehicles_by_fuel_type' => $vehiclesByFuelType
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

    public function expiringDocuments(Request $request)
    {
        try {
            $days = $request->get('days', 30);
            $documentType = $request->get('type', 'both'); // insurance, inspection, both

            $query = Vehicle::with(['driver']);

            if ($documentType === 'insurance') {
                $query->where('insurance_expiry', '<=', now()->addDays($days))
                      ->where('insurance_expiry', '>', now());
            } elseif ($documentType === 'inspection') {
                $query->where('inspection_expiry', '<=', now()->addDays($days))
                      ->where('inspection_expiry', '>', now());
            } else {
                $query->where(function($q) use ($days) {
                    $q->where('insurance_expiry', '<=', now()->addDays($days))
                      ->where('insurance_expiry', '>', now())
                      ->orWhere('inspection_expiry', '<=', now()->addDays($days))
                      ->where('inspection_expiry', '>', now());
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'insurance_expiry');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $vehicles = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'vehicles' => $vehicles->items(),
                    'pagination' => [
                        'current_page' => $vehicles->currentPage(),
                        'per_page' => $vehicles->perPage(),
                        'total' => $vehicles->total(),
                        'last_page' => $vehicles->lastPage(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve vehicles with expiring documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $query = Vehicle::with(['driver', 'createdBy']);

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('reg_no', 'like', '%' . $search . '%')
                      ->orWhere('make', 'like', '%' . $search . '%')
                      ->orWhere('model', 'like', '%' . $search . '%');
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $vehicles = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'vehicles' => $vehicles->items(),
                    'pagination' => [
                        'current_page' => $vehicles->currentPage(),
                        'per_page' => $vehicles->perPage(),
                        'total' => $vehicles->total(),
                        'last_page' => $vehicles->lastPage(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search vehicles',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

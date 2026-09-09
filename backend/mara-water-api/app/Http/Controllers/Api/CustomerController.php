<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers
     */
    public function index(Request $request)
    {
        try {
            $query = Customer::with(['route'])
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('route_id')) {
                $query->where('route_id', $request->route_id);
            }

            if ($request->filled('price_tier')) {
                $query->where('price_tier', $request->price_tier);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $customers = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $customers->items()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created customer
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|max:50|unique:customers,code',
                'name' => 'required|string|max:255',
                'type' => 'required|in:retail,wholesale,corporate',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'address' => 'nullable|string|max:500',
                'route_id' => 'nullable|exists:routes,id',
                'price_tier' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customer = Customer::create([
                'code' => $request->code,
                'name' => $request->name,
                'type' => $request->type,
                'phone' => $request->phone,
                'email' => $request->email,
                'address' => $request->address,
                'route_id' => $request->route_id,
                'price_tier' => $request->price_tier,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $customer->load(['route']);

            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'data' => [
                    'customer' => $customer
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified customer
     */
    public function show($id)
    {
        try {
            $customer = Customer::with(['route'])
                ->whereNull('deleted_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'customer' => $customer
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'code' => 'nullable|string|max:50|unique:customers,code,' . $id,
                'name' => 'nullable|string|max:255',
                'type' => 'nullable|in:retail,wholesale,corporate',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'address' => 'nullable|string|max:500',
                'route_id' => 'nullable|exists:routes,id',
                'price_tier' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $customer->update([
                'code' => $request->code ?? $customer->code,
                'name' => $request->name ?? $customer->name,
                'type' => $request->type ?? $customer->type,
                'phone' => $request->phone ?? $customer->phone,
                'email' => $request->email ?? $customer->email,
                'address' => $request->address ?? $customer->address,
                'route_id' => $request->route_id ?? $customer->route_id,
                'price_tier' => $request->price_tier ?? $customer->price_tier,
                'updated_by' => Auth::id(),
            ]);

            $customer->load(['route']);

            return response()->json([
                'success' => true,
                'message' => 'Customer updated successfully',
                'data' => [
                    'customer' => $customer
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified customer
     */
    public function destroy($id)
    {
        try {
            $customer = Customer::whereNull('deleted_at')->findOrFail($id);
            
            // Check if customer has active orders
            $activeOrders = $customer->orders()
                ->whereIn('status', ['draft', 'confirmed', 'dispatched'])
                ->count();

            if ($activeOrders > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete customer with active orders'
                ], 422);
            }

            // Soft delete
            $customer->update([
                'deleted_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Customer deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer statistics
     */
    public function statistics(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());

            $stats = Customer::whereNull('deleted_at')
                ->selectRaw('
                    COUNT(*) as total_customers,
                    COUNT(CASE WHEN type = "retail" THEN 1 END) as retail_customers,
                    COUNT(CASE WHEN type = "wholesale" THEN 1 END) as wholesale_customers,
                    COUNT(CASE WHEN type = "corporate" THEN 1 END) as corporate_customers,
                    COUNT(CASE WHEN route_id IS NOT NULL THEN 1 END) as assigned_route_customers,
                    COUNT(CASE WHEN route_id IS NULL THEN 1 END) as unassigned_route_customers
                ')
                ->first();

            // Get top customers by order value
            $topCustomers = Customer::whereNull('deleted_at')
                ->withSum(['orders' => function($query) use ($dateFrom, $dateTo) {
                    $query->whereBetween('order_date', [$dateFrom, $dateTo])
                          ->whereIn('status', ['delivered', 'partially_returned']);
                }], 'total_amount')
                ->orderByDesc('orders_sum_total_amount')
                ->limit(10)
                ->get(['id', 'name', 'type', 'orders_sum_total_amount']);

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ],
                    'statistics' => [
                        'total_customers' => $stats->total_customers,
                        'retail_customers' => $stats->retail_customers,
                        'wholesale_customers' => $stats->wholesale_customers,
                        'corporate_customers' => $stats->corporate_customers,
                        'assigned_route_customers' => $stats->assigned_route_customers,
                        'unassigned_route_customers' => $stats->unassigned_route_customers,
                    ],
                    'top_customers' => $topCustomers
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
     * Get customers by route
     */
    public function byRoute(Request $request, $routeId)
    {
        try {
            $query = Customer::with(['route'])
                ->where('route_id', $routeId)
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $customers = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $customers->items(),
                    'pagination' => [
                        'current_page' => $customers->currentPage(),
                        'per_page' => $customers->perPage(),
                        'total' => $customers->total(),
                        'last_page' => $customers->lastPage(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customers for route',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search customers
     */
    public function search(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'query' => 'required|string|min:2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $query = $request->query;
            $customers = Customer::whereNull('deleted_at')
                ->where(function($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                      ->orWhere('code', 'like', "%{$query}%")
                      ->orWhere('phone', 'like', "%{$query}%")
                      ->orWhere('email', 'like', "%{$query}%");
                })
                ->with(['route'])
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $customers
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search customers',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk assign customers to route
     */
    public function bulkAssignRoute(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_ids' => 'required|array|min:1',
                'customer_ids.*' => 'exists:customers,id',
                'route_id' => 'required|exists:routes,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updatedCount = Customer::whereIn('id', $request->customer_ids)
                ->whereNull('deleted_at')
                ->update([
                    'route_id' => $request->route_id,
                    'updated_by' => Auth::id(),
                ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully assigned {$updatedCount} customers to route",
                'data' => [
                    'updated_count' => $updatedCount
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign customers to route',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

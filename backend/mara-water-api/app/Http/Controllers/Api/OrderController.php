<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Customer;
use App\Models\User;
use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Display a listing of orders
     */
    public function index(Request $request)
    {
        try {
            $query = Order::with(['customer', 'salesOfficer'])
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            if ($request->filled('sales_officer_id')) {
                $query->where('sales_officer_id', $request->sales_officer_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('order_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('order_date', '<=', $request->date_to);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'order_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $orders = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $orders->items()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created order
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'order_date' => 'required|date',
                'requested_date' => 'nullable|date|after_or_equal:order_date',
                'sales_officer_id' => 'nullable|exists:users,id',
                'route_id' => 'nullable|exists:routes,id',
                'price_list_id' => 'nullable|exists:price_lists,id',
                'items' => 'required|array|min:1',
                'items.*.sku_id' => 'required|exists:skus,id',
                'items.*.qty' => 'required|integer|min:1',
                'items.*.unit_price' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Generate unique order number
            $orderNumber = $this->generateOrderNumber();

            $order = Order::create([
                'order_no' => $orderNumber,
                'customer_id' => $request->customer_id,
                'order_date' => $request->order_date,
                'requested_date' => $request->requested_date,
                'sales_officer_id' => $request->sales_officer_id ?? Auth::id(),
                'route_id' => $request->route_id,
                'price_list_id' => $request->price_list_id,
                'status' => 'draft',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Create order items
            $totalAmount = 0;
            foreach ($request->items as $item) {
                $unitPrice = $item['unit_price'] ?? $this->getDefaultUnitPrice($item['sku_id']);
                $lineTotal = $item['qty'] * $unitPrice;
                $totalAmount += $lineTotal;

                $order->items()->create([
                    'sku_id' => $item['sku_id'],
                    'qty' => $item['qty'],
                    'unit_price' => $unitPrice,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            // Update order with total amount
            $order->update(['total_amount' => $totalAmount]);

            $order->load(['customer', 'salesOfficer', 'items.sku']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'order' => $order
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified order
     */
    public function show($id)
    {
        try {
            $order = Order::with(['customer', 'salesOfficer', 'items.sku'])
                ->whereNull('deleted_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified order
     */
    public function update(Request $request, $id)
    {
        try {
            $order = Order::whereNull('deleted_at')->findOrFail($id);

            // Only allow updates if order is in draft status
            if ($order->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update order that is not in draft status'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'requested_date' => 'nullable|date|after_or_equal:order_date',
                'sales_officer_id' => 'nullable|exists:users,id',
                'route_id' => 'nullable|exists:routes,id',
                'price_list_id' => 'nullable|exists:price_lists,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order->update([
                'requested_date' => $request->requested_date ?? $order->requested_date,
                'sales_officer_id' => $request->sales_officer_id ?? $order->sales_officer_id,
                'route_id' => $request->route_id ?? $order->route_id,
                'price_list_id' => $request->price_list_id ?? $order->price_list_id,
                'updated_by' => Auth::id(),
            ]);

            $order->load(['customer', 'salesOfficer', 'items.sku']);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => [
                    'order' => $order
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified order
     */
    public function destroy($id)
    {
        try {
            $order = Order::whereNull('deleted_at')->findOrFail($id);
            
            // Only allow deletion if order is in draft status
            if ($order->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete order that is not in draft status'
                ], 422);
            }

            DB::beginTransaction();

            // Delete order items
            $order->items()->delete();

            // Soft delete order
            $order->update([
                'deleted_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $order = Order::whereNull('deleted_at')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:draft,confirmed,dispatched,delivered,partially_returned,cancelled',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $newStatus = $request->status;
            $currentStatus = $order->status;

            // Validate status transitions
            if (!$this->isValidStatusTransition($currentStatus, $newStatus)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid status transition from ' . $currentStatus . ' to ' . $newStatus
                ], 422);
            }

            $order->update([
                'status' => $newStatus,
                'updated_by' => Auth::id(),
            ]);

            $order->load(['customer', 'salesOfficer', 'items.sku']);

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => [
                    'order' => $order
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get order statistics
     */
    public function statistics(Request $request)
    {
        try {
            $dateFrom = $request->get('date_from', now()->subDays(30)->toDateString());
            $dateTo = $request->get('date_to', now()->toDateString());

            $stats = Order::whereNull('deleted_at')
                ->whereBetween('order_date', [$dateFrom, $dateTo])
                ->selectRaw('
                    COUNT(*) as total_orders,
                    COUNT(CASE WHEN status = "draft" THEN 1 END) as draft_orders,
                    COUNT(CASE WHEN status = "confirmed" THEN 1 END) as confirmed_orders,
                    COUNT(CASE WHEN status = "dispatched" THEN 1 END) as dispatched_orders,
                    COUNT(CASE WHEN status = "delivered" THEN 1 END) as delivered_orders,
                    COUNT(CASE WHEN status = "partially_returned" THEN 1 END) as returned_orders,
                    COUNT(CASE WHEN status = "cancelled" THEN 1 END) as cancelled_orders,
                    SUM(total_amount) as total_revenue,
                    AVG(total_amount) as avg_order_value
                ')
                ->first();

            $deliveryRate = $stats->total_orders > 0 
                ? round((($stats->delivered_orders + $stats->partially_returned_orders) / $stats->total_orders) * 100, 2) 
                : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ],
                    'statistics' => [
                        'total_orders' => $stats->total_orders,
                        'draft_orders' => $stats->draft_orders,
                        'confirmed_orders' => $stats->confirmed_orders,
                        'dispatched_orders' => $stats->dispatched_orders,
                        'delivered_orders' => $stats->delivered_orders,
                        'returned_orders' => $stats->returned_orders,
                        'cancelled_orders' => $stats->cancelled_orders,
                        'total_revenue' => round($stats->total_revenue, 2),
                        'avg_order_value' => round($stats->avg_order_value, 2),
                        'delivery_rate' => $deliveryRate,
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
     * Get orders by customer
     */
    public function byCustomer(Request $request, $customerId)
    {
        try {
            $query = Order::with(['customer', 'salesOfficer', 'items.sku'])
                ->where('customer_id', $customerId)
                ->whereNull('deleted_at');

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('order_date', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('order_date', '<=', $request->date_to);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'order_date');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('limit', 15);
            $orders = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orders->items(),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                        'last_page' => $orders->lastPage(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders for customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate unique order number
     */
    private function generateOrderNumber()
    {
        $date = date('Ymd');
        $count = Order::whereDate('created_at', today())->count();
        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        
        return "ORD-{$date}-{$sequence}";
    }

    /**
     * Get default unit price for SKU
     */
    private function getDefaultUnitPrice($skuId)
    {
        // This would typically come from price lists or SKU pricing
        // For now, return a default value
        return 50.00;
    }

    /**
     * Validate status transitions
     */
    private function isValidStatusTransition($currentStatus, $newStatus)
    {
        $validTransitions = [
            'draft' => ['confirmed', 'cancelled'],
            'confirmed' => ['dispatched', 'cancelled'],
            'dispatched' => ['delivered', 'partially_returned'],
            'delivered' => ['partially_returned'],
            'partially_returned' => [],
            'cancelled' => [],
        ];

        return in_array($newStatus, $validTransitions[$currentStatus] ?? []);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Customer;
use App\Models\User;
use App\Models\Sku;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Invoice;
use App\Models\Debt;
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
     * Log a Sale -- the single-flow POS-style replacement for the old
     * "one Excel tab per day per outlet" process. Unlike store() (which
     * creates a draft order that has to be walked through confirm ->
     * dispatch -> deliver by hand), this captures a completed transaction
     * in one call: what left the outlet, what came back, how it was paid
     * for, and who owes what -- and posts the knock-on effects itself
     * (a stock-in movement for returns, an invoice + debtor entry for
     * credit sales) instead of leaving them as separate manual steps.
     */
    public function logSale(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'warehouse_id' => 'required|exists:warehouses,id',
                'customer_id' => 'nullable|exists:customers,id',
                'order_date' => 'nullable|date',
                'price_list_id' => 'nullable|exists:price_lists,id',
                'payment_method' => 'required|in:cash,mpesa,credit',
                'payment_reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'items' => 'required|array|min:1',
                'items.*.sku_id' => 'required|exists:skus,id',
                'items.*.qty' => 'required|integer|min:1',
                'items.*.qty_returned' => 'nullable|integer|min:0',
                'items.*.unit_price' => 'nullable|numeric|min:0',
                'items.*.override_reason' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->payment_method === 'credit' && !$request->customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'A customer is required for credit sales',
                    'errors' => ['customer_id' => ['Required when payment method is credit']]
                ], 422);
            }

            // Resolve the price list once: the one picked, else whichever is
            // flagged default, so unlisted SKUs have somewhere to fall back to.
            $priceList = $request->price_list_id
                ? PriceList::find($request->price_list_id)
                : PriceList::where('is_default', true)->first();
            $listPrices = $priceList
                ? PriceListItem::where('price_list_id', $priceList->id)->pluck('unit_price', 'sku_id')
                : collect();

            $errors = [];
            foreach ($request->items as $i => $item) {
                $qtyReturned = $item['qty_returned'] ?? 0;
                if ($qtyReturned > $item['qty']) {
                    $errors["items.{$i}.qty_returned"] = ['Cannot return more than was dispatched'];
                }
                $listPrice = $listPrices->get($item['sku_id']);
                $overridden = isset($item['unit_price']) && $listPrice !== null
                    && bccomp((string) $item['unit_price'], (string) $listPrice, 2) !== 0;
                if ($overridden && empty($item['override_reason'])) {
                    $errors["items.{$i}.override_reason"] = ['Required when the price list price is overridden'];
                }
                if (!isset($item['unit_price']) && $listPrice === null) {
                    $errors["items.{$i}.unit_price"] = ['No price list has a price for this product -- enter one'];
                }
            }
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $errors
                ], 422);
            }

            DB::beginTransaction();

            $orderDate = $request->order_date ?? now()->toDateString();

            $order = Order::create([
                'order_no' => $this->generateOrderNumber(),
                'customer_id' => $request->customer_id,
                'warehouse_id' => $request->warehouse_id,
                'sales_officer_id' => Auth::id(),
                'price_list_id' => $priceList?->id,
                'status' => 'delivered',
                'order_date' => $orderDate,
                'requested_date' => $orderDate,
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $totalAmount = 0;
            $inventoryController = new InventoryController();

            foreach ($request->items as $item) {
                $sku = Sku::find($item['sku_id']);
                $qtyReturned = $item['qty_returned'] ?? 0;
                $listPrice = $listPrices->get($item['sku_id']);
                $unitPrice = $item['unit_price'] ?? $listPrice;
                $overridden = isset($item['unit_price']) && $listPrice !== null
                    && bccomp((string) $item['unit_price'], (string) $listPrice, 2) !== 0;

                $orderItem = $order->items()->create([
                    'sku_id' => $item['sku_id'],
                    'qty' => $item['qty'],
                    'qty_returned' => $qtyReturned,
                    'unit_price' => $unitPrice,
                    'unit_price_overridden' => $overridden,
                    'override_reason' => $overridden ? ($item['override_reason'] ?? null) : null,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                $totalAmount += $orderItem->line_total;

                // Returns feed straight back into inventory as a stock-in
                // movement against the outlet the sale was logged at.
                if ($qtyReturned > 0) {
                    $inventoryController->recordMove([
                        'move_type' => 'return',
                        'item_type' => 'sku',
                        'sku_id' => $item['sku_id'],
                        'warehouse_to_id' => $request->warehouse_id,
                        'qty' => $qtyReturned,
                        'uom' => $sku->unit ?? 'BOTTLE',
                        'ref_entity' => 'order',
                        'ref_id' => $order->id,
                    ]);
                }
            }

            $order->update(['total_amount' => $totalAmount]);

            $invoice = null;
            if ($request->payment_method === 'credit') {
                $invoice = Invoice::create([
                    'invoice_no' => $this->generateInvoiceNumber(),
                    'order_id' => $order->id,
                    'customer_id' => $request->customer_id,
                    'invoice_date' => $orderDate,
                    // No per-customer credit term is captured yet (Customer's
                    // payment_terms from Phase 6 is a payment-method
                    // preference, not a day count) -- Net 30 is a standard
                    // KES trade-credit default; Phase 9 can make this
                    // configurable per customer if that turns out to matter.
                    'due_date' => \Carbon\Carbon::parse($orderDate)->addDays(30)->toDateString(),
                    'subtotal' => $totalAmount,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total_amount' => $totalAmount,
                    'payment_terms' => 'Net 30',
                    'notes' => $request->notes,
                    'status' => 'sent',
                    'payment_status' => 'pending',
                    'sent_at' => now(),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                // Posts straight to the debtor ledger -- this is the manual
                // step the spec calls out as the biggest one to eliminate.
                // days_overdue isn't set here: a BEFORE INSERT trigger on
                // debts computes it from the linked invoice's due_date.
                Debt::create([
                    'customer_id' => $request->customer_id,
                    'invoice_id' => $invoice->id,
                    'principal' => $totalAmount,
                    'balance' => $totalAmount,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            $order->load(['customer', 'salesOfficer', 'warehouse', 'priceList', 'items.sku', 'invoice']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sale logged successfully',
                'data' => [
                    'order' => $order,
                    'invoice' => $invoice,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to log sale',
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
     * Generate unique invoice number. Same INV-YYYYMM-#### pattern as
     * InvoiceController::generateInvoiceNumber() -- duplicated rather than
     * shared because that one is private on a different controller, same
     * as generateOrderNumber() above already duplicates OrderController's
     * own numbering convention.
     */
    private function generateInvoiceNumber()
    {
        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');

        $lastInvoice = Invoice::where('invoice_no', 'like', "{$prefix}-{$year}{$month}-%")
            ->orderBy('invoice_no', 'desc')
            ->first();

        $newNumber = $lastInvoice ? ((int) substr($lastInvoice->invoice_no, -4)) + 1 : 1;

        return "{$prefix}-{$year}{$month}-" . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
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

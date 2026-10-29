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
            $query = Order::with(['customer', 'salesOfficer', 'warehouse', 'items.sku'])
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
     * Paid field-trip sales, in the same shape as an order row, so Manager
     * and Director sales lists include what drivers recorded on the road.
     */
    public function fieldSales(Request $request)
    {
        $sales = \App\Models\DriverTripSale::with(['customer', 'trip.driver', 'items.sku'])
            ->counted()
            ->whereNull('deleted_at')
            ->whereHas('trip', fn ($q) => $q->whereNull('deleted_at'))
            ->orderByDesc('created_at')
            ->limit((int) $request->get('limit', 100))
            ->get()
            ->map(function ($sale) {
                $driver = $sale->trip?->driver;
                return [
                    'id' => $sale->id,
                    'order_no' => 'Field',
                    'order_date' => optional($sale->trip)->trip_date,
                    'status' => 'delivered',
                    'source' => 'field',
                    'total_amount' => (string) $sale->amount,
                    'payment_method' => $sale->payment_method === 'debt' ? 'credit' : $sale->payment_method,
                    'payment_reference' => $sale->mpesa_reference,
                    'customer' => $sale->customer ? [
                        'id' => $sale->customer->id,
                        'name' => $sale->customer->name,
                        'code' => $sale->customer->code,
                    ] : null,
                    'warehouse' => ['name' => $sale->trip?->route ?: 'Field trip'],
                    'sales_officer' => [
                        'first_name' => $driver->first_name ?? 'Driver',
                        'last_name' => $driver->last_name ?? '',
                    ],
                    'items' => $sale->items->map(fn ($line) => [
                        'sku_name' => $line->sku->name ?? 'Item',
                        'qty' => (float) $line->qty_bales,
                    ])->values(),
                ];
            });

        return response()->json(['success' => true, 'data' => $sales]);
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
                // Round 3 Phase 9: which branch/outlet (KDN/KDQ/Warehouse)
                // this sale is attributed to, for the Daily Sales & Debt
                // export -- separate from warehouse_id (the physical
                // depot). Optional: older/untagged sales just show as
                // unattributed rather than guessing at real business data.
                'location_id' => 'nullable|exists:locations,id',
                'customer_id' => 'nullable|exists:customers,id',
                'order_date' => 'nullable|date',
                'price_list_id' => 'nullable|exists:price_lists,id',
                'payment_method' => 'required|in:cash,mpesa,credit',
                'payment_reference' => 'nullable|string|max:255',
                // Round 2 Phase 6: every credit sale needs a named,
                // accountable signatory and a deliberately short (max 7
                // days) repayment promise -- debt sales are discouraged,
                // not banned, so this is enforced only when payment_method
                // is actually credit, not on every sale.
                'signatory' => 'required_if:payment_method,credit|nullable|string|max:150',
                // Director may leave repayment open-ended (indefinite credit).
                'expected_repayment_date' => 'nullable|date',
                'buyer_name' => 'nullable|string|max:150',
                'sale_channel' => 'nullable|in:walk_in,field,outlet',
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

            $user = $request->user();
            $tier = $user?->role?->access_tier;
            $isDirector = $tier === 'director' || ($user?->role?->code === 'ADMIN');

            if ($request->payment_method === 'credit' && ! $isDirector) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a Director can log a credit sale.',
                ], 403);
            }

            // Managers implement the price list as-is — no overrides.
            if (! $isDirector) {
                foreach ($request->items as $i => $item) {
                    if (! empty($item['override_reason']) || (isset($item['unit_price']) && $request->boolean('force_price_override'))) {
                        // strip override intent; price will fall back to list below
                    }
                    if (! empty($item['override_reason'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Only a Director can override the price list.',
                            'errors' => ["items.{$i}.override_reason" => ['Director only']],
                        ], 403);
                    }
                }
            }

            if ($request->payment_method === 'credit' && ! $request->customer_id && ! $request->filled('buyer_name')) {
                return response()->json([
                    'success' => false,
                    'message' => 'A customer or buyer name is required for credit sales',
                    'errors' => ['customer_id' => ['Required when payment method is credit']]
                ], 422);
            }

            if ($request->payment_method === 'credit' && ! $request->filled('signatory') && $request->filled('buyer_name')) {
                $request->merge(['signatory' => trim((string) $request->buyer_name)]);
            }

            // Manager cash lock is enforced server-side (same idea as driver cash).
            if (! $isDirector
                && $request->payment_method === 'cash'
                && \App\Services\ManagerUiLocksService::isLocked($user, 'manager_cash_payment')
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cash sales are locked for managers. Contact Admin to unlock, or use M-Pesa.',
                ], 403);
            }

            $orderDateParsed = \Carbon\Carbon::parse($request->order_date ?? now('Africa/Nairobi')->toDateString(), 'Africa/Nairobi')->startOfDay();
            $todayNairobi = now('Africa/Nairobi')->startOfDay();
            if ($orderDateParsed->gt($todayNairobi)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sale date cannot be in the future',
                    'errors' => ['order_date' => ['Cannot be after today']],
                ], 422);
            }
            // Catch-up logging: directors always; managers only when Admin
            // unlocks "manager edit of sales (≤7 days)" and date is within 7 days.
            if ($orderDateParsed->lt($todayNairobi) && ! $isDirector) {
                $managerCatchUpOk = ! \App\Services\ManagerUiLocksService::isLocked($user, 'manager_edit_sales_7d')
                    && $orderDateParsed->gte($todayNairobi->copy()->subDays(7));
                if (! $managerCatchUpOk) {
                    return response()->json([
                        'success' => false,
                        'message' => \App\Services\ManagerUiLocksService::isLocked($user, 'manager_edit_sales_7d')
                            ? 'Backdated sales are locked. Contact Admin to unlock sales edits for the past 7 days.'
                            : 'Managers can only catch up sales within the last 7 days.',
                        'errors' => ['order_date' => ['Backdated sales need Admin unlock (≤7 days)']],
                    ], 422);
                }
            }
            if ($orderDateParsed->lt($todayNairobi->copy()->subDays(60))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sale date cannot be more than 60 days ago',
                    'errors' => ['order_date' => ['Choose a date within the last 60 days']],
                ], 422);
            }
            $isCatchUp = $orderDateParsed->lt($todayNairobi);

            // Optional repayment date — indefinite credit allowed for directors.
            if ($request->payment_method === 'credit' && $request->filled('expected_repayment_date')) {
                $saleDate = $orderDateParsed->copy();
                $repaymentDate = \Carbon\Carbon::parse($request->expected_repayment_date)->startOfDay();
                if ($repaymentDate->lt($saleDate)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Expected repayment date cannot be before the sale date',
                        'errors' => ['expected_repayment_date' => ['Cannot be before the sale date']]
                    ], 422);
                }
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
                    && $this->moneyDiffers($item['unit_price'], $listPrice);
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

            if ($request->payment_method === 'mpesa' && $request->filled('stk_phone')) {
                try {
                    \App\Services\MpesaStkService::normalizePhone($request->stk_phone);
                } catch (\InvalidArgumentException $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
                }
            }

            DB::beginTransaction();

            $orderDate = $orderDateParsed->toDateString();
            $customerId = $request->customer_id;

            // Single Main warehouse — auto-pick when client omits / sends blank.
            $warehouseId = $request->warehouse_id;
            if (! $warehouseId) {
                $warehouseId = \App\Models\Warehouse::query()
                    ->whereNull('deleted_at')
                    ->where(function ($q) {
                        $q->where('code', 'like', '%MAIN%')
                            ->orWhere('name', 'like', '%Main%')
                            ->orWhere('name', 'like', '%Warehouse%');
                    })
                    ->value('id')
                    ?: \App\Models\Warehouse::whereNull('deleted_at')->value('id');
                if (! $warehouseId) {
                    DB::rollBack();

                    return response()->json(['success' => false, 'message' => 'No warehouse configured'], 422);
                }
                $request->merge(['warehouse_id' => $warehouseId]);
            }

            // M-Pesa prompt phone doubles as customer phone.
            if ($request->filled('stk_phone') && ! $request->filled('buyer_phone')) {
                $request->merge(['buyer_phone' => $request->stk_phone]);
            }
            if ($request->filled('buyer_phone') && ! $request->filled('stk_phone') && $request->payment_method === 'mpesa') {
                $request->merge(['stk_phone' => $request->buyer_phone]);
            }

            // Walk-in / field buyer name → create a light customer record so
            // the name lands on the customer list automatically.
            if (! $customerId && $request->filled('buyer_name')) {
                $channel = $request->input('sale_channel', 'walk_in');
                $customer = \App\Models\Customer::create([
                    'code' => 'CUST-' . strtoupper(\Illuminate\Support\Str::random(6)),
                    'name' => trim((string) $request->buyer_name),
                    'type' => $channel === 'field' ? 'retail' : 'walk_in',
                    'phone' => $request->input('buyer_phone') ?: 'N/A',
                    'address' => $request->input('buyer_location'),
                    'price_tier' => 'standard',
                    'status' => 'active',
                    'notes' => $channel === 'field' ? 'Field sale (auto)' : 'Walk-in sale (auto)',
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                $customerId = $customer->id;
            }

            $deferStock = $request->payment_method === 'mpesa' && $request->filled('stk_phone');

            $noteParts = array_filter([
                $request->notes,
                $request->filled('sale_channel') ? ('Channel: ' . $request->sale_channel) : null,
                $request->filled('buyer_location') ? ('Location: ' . $request->buyer_location) : null,
            ]);

            $paymentStatus = $deferStock
                ? 'awaiting_payment'
                : ($request->payment_method === 'credit' ? 'unpaid' : 'paid');

            $order = Order::create([
                'order_no' => $this->generateOrderNumber(),
                'customer_id' => $customerId,
                'warehouse_id' => $warehouseId ?: $request->warehouse_id,
                'location_id' => null, // walk-in / outlet — no KDN/KDQ branch picker
                'sales_officer_id' => Auth::id(),
                'price_list_id' => $priceList?->id,
                'status' => $deferStock ? 'awaiting_payment' : 'delivered',
                'order_date' => $orderDate,
                'requested_date' => $orderDate,
                'payment_method' => $request->payment_method,
                'payment_status' => $paymentStatus,
                'stock_posted' => ! $deferStock,
                'payment_reference' => $request->payment_reference,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $totalAmount = 0;
            $stockWarnings = [];
            $inventoryController = new InventoryController();

            foreach ($request->items as $item) {
                $sku = Sku::find($item['sku_id']);
                $qtyReturned = $item['qty_returned'] ?? 0;
                $listPrice = $listPrices->get($item['sku_id']);
                $unitPrice = $item['unit_price'] ?? $listPrice;
                $overridden = isset($item['unit_price']) && $listPrice !== null
                    && $this->moneyDiffers($item['unit_price'], $listPrice);

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

                if ($deferStock) {
                    continue;
                }

                // Returns feed straight back into inventory as a stock-in
                // movement against the outlet the sale was logged at.
                // Custom SKUs return into the linked physical bottle stock.
                if ($qtyReturned > 0) {
                    $stockSku = \App\Services\SkuStockAliasService::stockSkuFor($sku);
                    $inventoryController->recordMove([
                        'move_type' => 'return',
                        'item_type' => 'sku',
                        'sku_id' => $stockSku?->id ?: $item['sku_id'],
                        'warehouse_to_id' => $request->warehouse_id,
                        'qty' => $qtyReturned,
                        'uom' => $stockSku->unit ?? $sku->unit ?? 'BOTTLE',
                        'ref_entity' => 'order',
                        'ref_id' => $order->id,
                    ]);
                }

                // The net quantity actually sold (dispatched minus what
                // came back) has to leave recorded stock, or the
                // inventory pages just keep showing produced quantities
                // forever regardless of what's been sold. Sku stock is
                // tracked per batch, and a sale doesn't ask which batch --
                // so draw down FEFO (soonest-expiring batch first, same
                // logic a warehouse would use by hand) across as many
                // batches as it takes to cover the quantity.
                $netQtySold = $item['qty'] - $qtyReturned;
                if ($netQtySold > 0) {
                    $stockWarnings = array_merge(
                        $stockWarnings,
                        $inventoryController->deductStock($item['sku_id'], $sku, $request->warehouse_id, $netQtySold, 'order', $order->id)
                    );
                }
            }

            $order->update(['total_amount' => $totalAmount]);

            if ($deferStock && (int) round($totalAmount) < 1) {
                DB::rollBack();

                return response()->json(['success' => false, 'message' => 'Amount must be at least KES 1 for M-Pesa.'], 422);
            }

            $invoice = null;
            if ($request->payment_method === 'credit') {
                if (! $customerId) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'A customer is required for credit sales',
                    ], 422);
                }

                $dueDate = $request->filled('expected_repayment_date')
                    ? \Carbon\Carbon::parse($request->expected_repayment_date)->toDateString()
                    : null; // indefinite credit — Director may leave open
                $invoice = Invoice::create([
                    'invoice_no' => $this->generateInvoiceNumber(),
                    'order_id' => $order->id,
                    'customer_id' => $customerId,
                    'invoice_date' => $orderDate,
                    'due_date' => $dueDate ?: \Carbon\Carbon::parse($orderDate)->addYears(10)->toDateString(),
                    'subtotal' => $totalAmount,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'total_amount' => $totalAmount,
                    'payment_terms' => $dueDate ? 'Agreed repayment' : 'Indefinite / open credit',
                    'notes' => implode(' · ', $noteParts) ?: null,
                    'status' => 'sent',
                    'payment_status' => 'pending',
                    'sent_at' => now(),
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                // Posts straight to the debtor ledger -- this is the manual
                // step the spec calls out as the biggest one to eliminate.
                // days_overdue is a live accessor on the Debt model now
                // (Round 2 Phase 6) -- computed from expected_repayment_date,
                // not stored/frozen at creation.
                $debt = Debt::create([
                    'customer_id' => $customerId,
                    'invoice_id' => $invoice->id,
                    'signatory' => $request->signatory ?: ($request->buyer_name ?: 'Director'),
                    'expected_repayment_date' => $request->expected_repayment_date,
                    'principal' => $totalAmount,
                    'balance' => $totalAmount,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                // Phase 9's debtors ledger reads this, not the debts table
                // directly -- the ledger is the full transaction history
                // (this debit, plus every later payment credit); debts.balance
                // is just the current running total DebtorLedgerController
                // keeps in sync with it.
                \App\Models\DebtorLedgerEntry::create([
                    'customer_id' => $customerId,
                    'debt_id' => $debt->id,
                    'entry_date' => $orderDate,
                    'details' => "Credit sale - Invoice {$invoice->invoice_no}",
                    'reference_no' => $invoice->invoice_no,
                    'debit' => $totalAmount,
                    'credit' => 0,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            $order->load(['customer', 'salesOfficer', 'warehouse', 'priceList', 'items.sku', 'invoice']);

            DB::commit();

            $payment = null;
            $stkError = null;
            if ($deferStock) {
                try {
                    $payment = app(\App\Services\MpesaStkService::class)->startForOrder(
                        $order->fresh(),
                        $request->stk_phone,
                        $request->user(),
                        $request->input('stk_channel', 'warehouse')
                    );
                } catch (\Throwable $e) {
                    $stkError = $e->getMessage();
                    $payment = \App\Models\MpesaPayment::where('order_id', $order->id)->latest()->first();
                }
            }

            return response()->json([
                'success' => true,
                'message' => $isCatchUp
                    ? 'Catch-up sale logged successfully'
                    : 'Sale logged successfully',
                'data' => [
                    'order' => $order,
                    'invoice' => $invoice,
                    // Directors backfilling offline days should not be blocked by
                    // negative-stock toasts — stock still deducts for the ledger.
                    'stock_warnings' => ($isCatchUp && $isDirector) ? [] : $stockWarnings,
                    'catch_up' => $isCatchUp,
                    'payment' => $payment,
                    'stk_error' => $stkError,
                ]
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('logSale failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to log sale',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /** Compare money without requiring ext-bcmath (not installed on Railway). */
    private function moneyDiffers(mixed $a, mixed $b): bool
    {
        return round((float) $a, 2) !== round((float) $b, 2);
    }

    // Round 2 Phase 8: the FEFO stock-deduction logic that used to live
    // here as a private method now lives in InventoryController::deductStock()
    // -- shared with Driver Trips instead of duplicated, per "one single
    // stock ledger that everything writes to and reads from".

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
            $order = Order::with(['items', 'invoice'])->whereNull('deleted_at')->findOrFail($id);
            $user = $request->user();
            $tier = $user?->role?->access_tier;
            $isDirector = $tier === 'director' || ($user?->role?->code === 'ADMIN');

            // Drafts: managers can edit routing fields.
            // Posted sales: Director always; managers when Admin unlocks ≤7-day edits
            // and the sale date is within the past week.
            if ($order->status !== 'draft' && ! $isDirector) {
                $orderDay = \Carbon\Carbon::parse($order->order_date, 'Africa/Nairobi')->startOfDay();
                $todayNairobi = now('Africa/Nairobi')->startOfDay();
                $withinWeek = $orderDay->gte($todayNairobi->copy()->subDays(7)) && $orderDay->lte($todayNairobi);
                $editUnlocked = ! \App\Services\ManagerUiLocksService::isLocked($user, 'manager_edit_sales_7d')
                    && ! \App\Services\ManagerUiLocksService::isLocked($user, 'manager_edit_sales');
                if (! $withinWeek || ! $editUnlocked) {
                    return response()->json([
                        'success' => false,
                        'message' => ! $editUnlocked
                            ? 'Sale edits are locked. Contact Admin to unlock manager sale edits (≤7 days).'
                            : 'Managers can only alter sales from the past 7 days.',
                    ], 403);
                }
                if ($request->payment_method === 'cash'
                    && \App\Services\ManagerUiLocksService::isLocked($user, 'manager_cash_payment')
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cash is locked for managers. Contact Admin or keep M-Pesa.',
                    ], 403);
                }
                if ($request->payment_method === 'credit') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only a Director can set a sale to credit.',
                    ], 403);
                }
            }

            if ($order->status === 'draft') {
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
            } else {
                $validator = Validator::make($request->all(), [
                    'customer_id' => 'nullable|exists:customers,id',
                    'order_date' => 'nullable|date',
                    'payment_method' => 'nullable|in:cash,mpesa,credit',
                    'payment_reference' => 'nullable|string|max:120',
                    'payment_status' => 'nullable|in:pending,paid,partial,failed,cancelled',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $patch = ['updated_by' => Auth::id()];
                foreach (['customer_id', 'order_date', 'payment_method', 'payment_reference', 'payment_status'] as $field) {
                    if ($request->exists($field)) {
                        $patch[$field] = $request->input($field);
                    }
                }
                $order->update($patch);
            }

            $order->load(['customer', 'salesOfficer', 'items.sku', 'warehouse', 'invoice']);

            return response()->json([
                'success' => true,
                'message' => 'Sale updated successfully',
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
     * Remove an order / outlet sale.
     * Drafts: any manager/director.
     * Posted sales (delivered, awaiting payment, etc.): Director only —
     * reverses stock, voids linked M-Pesa rows, and soft-deletes invoice/debt.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $order = Order::with(['items', 'invoice'])->whereNull('deleted_at')->findOrFail($id);
            $tier = $request->user()?->role?->access_tier;
            $isDirector = $tier === 'director' || ($request->user()?->role?->code === 'ADMIN');

            if ($order->status !== 'draft' && ! $isDirector) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only a Director can delete a posted sale. Use this for test entries or mistakes.',
                ], 403);
            }

            DB::beginTransaction();

            // Always reverse posted stock (incl. awaiting_payment that later posted).
            if ($order->stock_posted || in_array($order->status, ['delivered', 'dispatched', 'partially_returned', 'confirmed', 'awaiting_payment'], true)) {
                $this->reverseOrderStock($order);
            }

            \App\Models\MpesaPayment::where('order_id', $order->id)->get()->each(function ($payment) {
                if ($payment->status === 'pending') {
                    $payment->update([
                        'status' => 'cancelled',
                        'result_desc' => 'Sale deleted by director',
                    ]);
                }
                $payment->delete();
            });

            if ($order->invoice) {
                $invoice = $order->invoice;
                Debt::where('invoice_id', $invoice->id)->get()->each(function ($debt) {
                    \App\Models\DebtorLedgerEntry::where('debt_id', $debt->id)->delete();
                    $debt->delete();
                });
                $invoice->delete();
            }

            // Soft-delete items/order but keep original status so restore
            // can bring the sale back as-was (do not force 'cancelled').
            $order->items()->delete();
            $order->update(['updated_by' => Auth::id()]);
            $order->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sale deleted. Stock was reversed where it had been posted.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sale',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Director-only: correct a field/trip sale (payment method, reference, amount, buyer).
     * Does not rewrite line items — delete and re-log if the SKU mix was wrong.
     */
    public function updateFieldSale(Request $request, $id)
    {
        $tier = $request->user()?->role?->access_tier;
        if ($tier !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Only a Director can alter a field sale.',
            ], 403);
        }

        $sale = \App\Models\DriverTripSale::with(['customer', 'trip.driver', 'items.sku'])
            ->whereNull('deleted_at')
            ->find($id);
        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'Field sale not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'customer_id' => 'nullable|exists:customers,id',
            'payment_method' => 'nullable|in:cash,mpesa,debt,credit',
            'payment_reference' => 'nullable|string|max:120',
            'mpesa_reference' => 'nullable|string|max:120',
            'amount' => 'nullable|numeric|min:0',
            'payment_status' => 'nullable|in:pending,paid,partial,failed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $method = $request->input('payment_method', $sale->payment_method);
        if ($method === 'credit') {
            $method = 'debt';
        }

        $ref = $request->exists('mpesa_reference')
            ? $request->input('mpesa_reference')
            : ($request->exists('payment_reference') ? $request->input('payment_reference') : $sale->mpesa_reference);

        $sale->update([
            'customer_id' => $request->exists('customer_id') ? $request->input('customer_id') : $sale->customer_id,
            'payment_method' => $method,
            'mpesa_reference' => $ref,
            'amount' => $request->exists('amount') ? $request->input('amount') : $sale->amount,
            'payment_status' => $request->exists('payment_status') ? $request->input('payment_status') : $sale->payment_status,
            'updated_by' => Auth::id(),
        ]);

        $sale->refresh()->load(['customer', 'trip.driver', 'items.sku']);

        return response()->json([
            'success' => true,
            'message' => 'Field sale updated.',
            'data' => $sale,
        ]);
    }

    /**
     * Director-only: remove a field/trip sale used for testing or keyed in error.
     * Warehouse stock is not reversed here — dispatch already left with the truck;
     * removing the sale only clears the sale/debt/M-Pesa records from tallies.
     */
    public function destroyFieldSale(Request $request, $id)
    {
        $tier = $request->user()?->role?->access_tier;
        if ($tier !== 'director') {
            return response()->json([
                'success' => false,
                'message' => 'Only a Director can delete a field sale.',
            ], 403);
        }

        $sale = \App\Models\DriverTripSale::with(['items', 'debt'])->whereNull('deleted_at')->find($id);
        if (! $sale) {
            return response()->json(['success' => false, 'message' => 'Field sale not found'], 404);
        }

        try {
            DB::transaction(function () use ($sale) {
                \App\Models\MpesaPayment::where('driver_trip_sale_id', $sale->id)->get()->each(function ($payment) {
                    if ($payment->status === 'pending') {
                        $payment->update([
                            'status' => 'cancelled',
                            'result_desc' => 'Field sale deleted by director',
                        ]);
                    }
                    $payment->delete();
                });

                if ($sale->debt_id) {
                    $debt = Debt::find($sale->debt_id);
                    if ($debt) {
                        \App\Models\DebtorLedgerEntry::where('debt_id', $debt->id)->delete();
                        $debt->delete();
                    }
                }

                // Leave sale items intact — DriverTripSaleItem has no SoftDeletes;
                // hard-deleting them would make restore incomplete.
                $sale->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Field sale deleted.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete field sale',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Director-only: soft-deleted outlet orders + field sales for the trash UI.
     */
    public function trashed(Request $request)
    {
        if (! $this->requesterIsDirector($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a Director can view deleted sales.',
            ], 403);
        }

        $orders = Order::withTrashed()
            ->whereNotNull('deleted_at')
            ->with([
                'customer',
                'warehouse',
                'items' => fn ($q) => $q->withTrashed()->with('sku'),
            ])
            ->orderByDesc('deleted_at')
            ->limit(50)
            ->get();

        $fieldSales = \App\Models\DriverTripSale::withTrashed()
            ->whereNotNull('deleted_at')
            ->with(['customer', 'trip.driver', 'items.sku'])
            ->orderByDesc('deleted_at')
            ->limit(50)
            ->get()
            ->map(function ($sale) {
                $driver = $sale->trip?->driver;

                return [
                    'id' => $sale->id,
                    'order_no' => 'Field',
                    'order_date' => optional($sale->trip)->trip_date,
                    'status' => 'delivered',
                    'source' => 'field',
                    'trashed' => true,
                    'deleted_at' => $sale->deleted_at,
                    'total_amount' => (string) $sale->amount,
                    'payment_method' => $sale->payment_method === 'debt' ? 'credit' : $sale->payment_method,
                    'payment_reference' => $sale->mpesa_reference,
                    'customer' => $sale->customer ? [
                        'id' => $sale->customer->id,
                        'name' => $sale->customer->name,
                        'code' => $sale->customer->code,
                    ] : null,
                    'warehouse' => ['name' => $sale->trip?->route ?: 'Field trip'],
                    'sales_officer' => [
                        'first_name' => $driver->first_name ?? 'Driver',
                        'last_name' => $driver->last_name ?? '',
                    ],
                    'items' => $sale->items->map(fn ($line) => [
                        'sku_name' => $line->sku->name ?? 'Item',
                        'qty' => (float) $line->qty_bales,
                    ])->values(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'orders' => $orders,
                'field_sales' => $fieldSales,
            ],
        ]);
    }

    /**
     * Director-only: restore a soft-deleted outlet order and re-post stock when needed.
     */
    public function restore(Request $request, $id)
    {
        if (! $this->requesterIsDirector($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a Director can restore a deleted sale.',
            ], 403);
        }

        $order = Order::withTrashed()
            ->with(['items' => fn ($q) => $q->withTrashed()->with('sku')])
            ->find($id);

        if (! $order || ! $order->trashed()) {
            return response()->json(['success' => false, 'message' => 'Deleted order not found'], 404);
        }

        try {
            DB::beginTransaction();

            $order->restore();
            \App\Models\OrderItem::withTrashed()->where('order_id', $order->id)->restore();

            $invoice = Invoice::withTrashed()->where('order_id', $order->id)->first();
            if ($invoice) {
                $invoice->restore();
                Debt::withTrashed()->where('invoice_id', $invoice->id)->get()->each(function ($debt) {
                    $debt->restore();
                    \App\Models\DebtorLedgerEntry::withTrashed()->where('debt_id', $debt->id)->restore();
                });
            }

            // MpesaPayment has no SoftDeletes today — deletes were hard; skip restore.
            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(\App\Models\MpesaPayment::class), true)) {
                \App\Models\MpesaPayment::withTrashed()->where('order_id', $order->id)->restore();
            }

            $order->refresh()->load(['items.sku']);

            $shouldRepost = ! $order->stock_posted
                && in_array($order->status, ['delivered', 'dispatched', 'partially_returned', 'confirmed', 'awaiting_payment'], true);

            if ($shouldRepost) {
                $inventory = new InventoryController();
                foreach ($order->items as $item) {
                    $sku = $item->sku ?: Sku::find($item->sku_id);
                    $netQtySold = (int) $item->qty - (int) ($item->qty_returned ?? 0);
                    if ($netQtySold > 0 && $order->warehouse_id) {
                        $inventory->deductStock(
                            $item->sku_id,
                            $sku,
                            $order->warehouse_id,
                            $netQtySold,
                            'order',
                            $order->id
                        );
                    }
                }
                $order->update(['stock_posted' => true, 'updated_by' => Auth::id()]);
            } else {
                $order->update(['updated_by' => Auth::id()]);
            }

            DB::commit();

            $order->load(['customer', 'salesOfficer', 'warehouse', 'items.sku']);

            return response()->json([
                'success' => true,
                'message' => 'Sale restored.',
                'data' => ['order' => $order],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to restore sale',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Director-only: restore a soft-deleted field/trip sale (+ debt if soft-deleted).
     */
    public function restoreFieldSale(Request $request, $id)
    {
        if (! $this->requesterIsDirector($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Only a Director can restore a deleted field sale.',
            ], 403);
        }

        $sale = \App\Models\DriverTripSale::withTrashed()->find($id);
        if (! $sale || ! $sale->trashed()) {
            return response()->json(['success' => false, 'message' => 'Deleted field sale not found'], 404);
        }

        try {
            DB::transaction(function () use ($sale) {
                $sale->restore();

                if ($sale->debt_id) {
                    $debt = Debt::withTrashed()->find($sale->debt_id);
                    if ($debt && $debt->trashed()) {
                        $debt->restore();
                        \App\Models\DebtorLedgerEntry::withTrashed()->where('debt_id', $debt->id)->restore();
                    }
                }
            });

            $sale->refresh()->load(['customer', 'trip.driver', 'items.sku', 'debt']);

            return response()->json([
                'success' => true,
                'message' => 'Field sale restored.',
                'data' => $sale,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore field sale',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function requesterIsDirector(Request $request): bool
    {
        $user = $request->user();
        $tier = $user?->role?->access_tier;

        return $tier === 'director' || ($user?->role?->code === 'ADMIN') || (bool) $user?->isDirector();
    }

    private function reverseOrderStock(Order $order): void
    {
        $inventory = new InventoryController();
        $moves = \App\Models\StockMove::where('ref_entity', 'order')
            ->where('ref_id', $order->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($moves as $move) {
            if ($move->move_type === 'issue' && $move->warehouse_from_id) {
                $inventory->recordMove([
                    'move_type' => 'adjust',
                    'item_type' => $move->item_type,
                    'sku_id' => $move->sku_id,
                    'batch_id' => $move->batch_id,
                    'warehouse_to_id' => $move->warehouse_from_id,
                    'qty' => $move->qty,
                    'uom' => $move->uom,
                    'ref_entity' => 'order_deleted',
                    'ref_id' => $order->id,
                ]);
            } elseif ($move->move_type === 'return' && $move->warehouse_to_id) {
                $inventory->recordMove([
                    'move_type' => 'adjust',
                    'item_type' => $move->item_type,
                    'sku_id' => $move->sku_id,
                    'batch_id' => $move->batch_id,
                    'warehouse_from_id' => $move->warehouse_to_id,
                    'qty' => $move->qty,
                    'uom' => $move->uom,
                    'ref_entity' => 'order_deleted',
                    'ref_id' => $order->id,
                ], true);
            }
            $move->delete();
        }

        $order->update(['stock_posted' => false]);
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
     * Generate unique order number.
     * Must include soft-deleted rows — uk_orders_order_no still holds them,
     * and count()-only-active reused ORD-…-0001 after a director deleted a test sale.
     */
    private function generateOrderNumber(): string
    {
        $date = now('Africa/Nairobi')->format('Ymd');
        $prefix = "ORD-{$date}-";

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $last = Order::withTrashed()
                ->where('order_no', 'like', $prefix.'%')
                ->orderByDesc('order_no')
                ->value('order_no');

            $next = 1;
            if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
                $next = ((int) $m[1]) + 1;
            }
            $candidate = $prefix.str_pad((string) ($next + $attempt), 4, '0', STR_PAD_LEFT);

            $exists = Order::withTrashed()->where('order_no', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return $prefix.now('Africa/Nairobi')->format('His').'-'.strtoupper(\Illuminate\Support\Str::random(4));
    }

    /**
     * Fallback unit price for legacy store() when the client omits
     * unit_price — Round 5B: read the default price list via PriceService
     * instead of the old hardcoded 50.00.
     */
    private function getDefaultUnitPrice($skuId)
    {
        $price = (new \App\Services\PriceService())->resolveForSku((string) $skuId, 'default');
        return $price ?? 0.0;
    }

    /**
     * Generate unique invoice number. Same INV-YYYYMM-#### pattern as
     * InvoiceController::generateInvoiceNumber() -- duplicated rather than
     * shared because that one is private on a different controller, same
     * as generateOrderNumber() above already duplicates OrderController's
     * own numbering convention.
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $yearMonth = now('Africa/Nairobi')->format('Ym');

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $last = Invoice::withTrashed()
                ->where('invoice_no', 'like', "{$prefix}-{$yearMonth}-%")
                ->orderByDesc('invoice_no')
                ->value('invoice_no');

            $next = 1;
            if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
                $next = ((int) $m[1]) + 1;
            }
            $candidate = "{$prefix}-{$yearMonth}-".str_pad((string) ($next + $attempt), 4, '0', STR_PAD_LEFT);
            if (! Invoice::withTrashed()->where('invoice_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        return "{$prefix}-{$yearMonth}-".now('Africa/Nairobi')->format('His');
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

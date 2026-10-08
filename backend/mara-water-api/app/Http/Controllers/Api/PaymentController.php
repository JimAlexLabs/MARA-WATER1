<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverTrip;
use App\Models\DriverTripSaleItem;
use App\Models\MpesaPayment;
use App\Models\Order;
use App\Services\PaymentGatewayClient;
use App\Services\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Round 6: M-Pesa STK Push via the shared AfriGig payment gateway.
 * MARA never talks to Safaricom/Daraja directly -- PaymentGatewayClient
 * is the only thing that talks to the gateway, and PaymentSettlementService
 * is the only thing that turns a settled result into real state (a
 * created sale, a confirmed Order). See both classes' docblocks.
 */
class PaymentController extends Controller
{
    public function __construct(
        private PaymentGatewayClient $gateway,
        private PaymentSettlementService $settlement,
    ) {
    }

    /**
     * POST /payments/stk -- Driver/Sales for channel=field_trip (Log a
     * Sale), Manager/Director for warehouse/refill/admin (an existing
     * Order already created via the normal Sales flow, still unpaid).
     * The amount is never taken from the browser -- field_trip computes
     * it from the submitted line items the same way addSale() does;
     * Order-based reads the order's own already-computed total_amount.
     */
    public function initiateStk(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'channel' => 'required|in:field_trip,warehouse,refill,admin',
            'phone' => ['required', 'string', 'regex:/^254(7|1)\d{8}$/'],
            // field_trip
            'driver_trip_id' => 'required_if:channel,field_trip|exists:driver_trips,id',
            'customer_id' => 'required_if:channel,field_trip|exists:customers,id',
            'items' => 'required_if:channel,field_trip|array|min:1',
            'items.*.sku_id' => 'required_with:items|exists:skus,id',
            'items.*.qty_bales' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'physical_receipt_no' => 'nullable|string|max:50',
            'physical_delivery_note_no' => 'nullable|string|max:50',
            'photo_url' => 'nullable|url|max:500',
            // warehouse/refill/admin
            'order_id' => 'required_if:channel,warehouse,refill,admin|exists:orders,id',
        ], [
            'phone.regex' => 'Phone must be a normalized Safaricom number, e.g. 2547XXXXXXXX or 2541XXXXXXXX',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if ($request->channel === 'field_trip') {
            return $this->initiateFieldTripStk($request);
        }
        return $this->initiateOrderStk($request);
    }

    private function initiateFieldTripStk(Request $request)
    {
        $trip = DriverTrip::with('items.sku', 'sales.items')->find($request->driver_trip_id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip not found'], 404);
        }
        if ($request->user()->hasAccessTier('driver') && $trip->driver_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this trip.'], 403);
        }
        if ($trip->status !== 'in_transit') {
            return response()->json(['success' => false, 'message' => 'Sales can only be recorded on a trip that is in transit'], 422);
        }

        // Same "available to sell" guard as addSale(), extended to also
        // reserve bales held by any OTHER still-pending STK push on this
        // trip -- otherwise two concurrent pushes for the last bales
        // could both succeed and double-sell them.
        $confirmedSold = DriverTripSaleItem::whereHas('sale', fn ($q) => $q->where('driver_trip_id', $trip->id)->whereNull('deleted_at'))
            ->selectRaw('sku_id, SUM(qty_bales) as sold')->groupBy('sku_id')->pluck('sold', 'sku_id');
        $reservedBySku = [];
        foreach (MpesaPayment::where('trip_id', $trip->id)->where('channel', 'field_trip')->where('status', 'pending')->get() as $pending) {
            foreach (($pending->pending_sale_snapshot['items'] ?? []) as $line) {
                $reservedBySku[$line['sku_id']] = ($reservedBySku[$line['sku_id']] ?? 0) + $line['qty_bales'];
            }
        }

        foreach ($request->input('items') as $line) {
            $tripItem = $trip->items->firstWhere('sku_id', $line['sku_id']);
            if (!$tripItem) {
                return response()->json(['success' => false, 'message' => 'That item was not dispatched on this trip', 'errors' => ['items' => ["{$line['sku_id']} was not dispatched on this trip"]]], 422);
            }
            $available = (float) $tripItem->qty_carried_bales - (float) ($confirmedSold[$line['sku_id']] ?? 0) - (float) ($reservedBySku[$line['sku_id']] ?? 0);
            if ((float) $line['qty_bales'] > $available + 0.001) {
                $skuName = $tripItem->sku->name ?? 'that item';
                return response()->json(['success' => false, 'message' => "Only {$available} bales of {$skuName} remain available on this trip (some may be held by another pending M-Pesa request)", 'errors' => ['items' => ["Only {$available} bales of {$skuName} remain available"]]], 422);
            }
        }

        $amount = round(collect($request->input('items'))->sum(fn ($l) => $l['qty_bales'] * $l['unit_price']), 2);
        if ($amount < 1) {
            return response()->json(['success' => false, 'message' => 'Amount must be at least KES 1'], 422);
        }

        $reference = $this->buildReference();
        $idempotencyKey = (string) Str::uuid();

        $payment = MpesaPayment::create([
            'channel' => 'field_trip',
            'trip_id' => $trip->id,
            'location_id' => $trip->location_id,
            'reference' => $reference,
            'phone' => $request->phone,
            'amount' => $amount,
            'status' => 'pending',
            'initiated_by' => Auth::id(),
            'idempotency_key' => $idempotencyKey,
            'pending_sale_snapshot' => [
                'driver_trip_id' => $trip->id,
                'customer_id' => $request->customer_id,
                'items' => $request->input('items'),
                'amount' => $amount,
                'physical_receipt_no' => $request->physical_receipt_no,
                'physical_delivery_note_no' => $request->physical_delivery_note_no,
                'photo_url' => $request->photo_url,
                'created_by' => Auth::id(),
            ],
        ]);

        return $this->callGatewayAndRespond($payment, $amount, "MARA Water field sale {$reference}");
    }

    private function initiateOrderStk(Request $request)
    {
        $order = Order::find($request->order_id);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }
        if ($order->payment_method) {
            return response()->json(['success' => false, 'message' => 'This order is already paid'], 422);
        }
        // Phase B7: "one active pending push per sale."
        if (MpesaPayment::where('order_id', $order->id)->where('status', 'pending')->exists()) {
            return response()->json(['success' => false, 'message' => 'A payment request is already pending for this order'], 422);
        }
        $amount = (float) $order->total_amount;
        if ($amount < 1) {
            return response()->json(['success' => false, 'message' => 'Order has no total to charge'], 422);
        }

        $reference = $this->buildReference();
        $payment = MpesaPayment::create([
            'channel' => $request->channel,
            'order_id' => $order->id,
            'location_id' => $order->location_id,
            'reference' => $reference,
            'phone' => $request->phone,
            'amount' => $amount,
            'status' => 'pending',
            'initiated_by' => Auth::id(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return $this->callGatewayAndRespond($payment, $amount, "MARA Water order {$order->order_no}");
    }

    private function buildReference(): string
    {
        // Max 12 chars per Phase 0's "safety net" layer (Safaricom's
        // AccountReference field limit) -- "MARA-" + 7 random base36
        // chars comfortably fits and stays collision-safe enough given
        // the unique DB constraint backs it up regardless.
        return 'MARA-' . Str::upper(Str::random(7));
    }

    private function callGatewayAndRespond(MpesaPayment $payment, float $amount, string $description)
    {
        try {
            $result = $this->gateway->initiateStk($payment->reference, $payment->phone, $amount, $description, $payment->idempotency_key);
        } catch (\Throwable $e) {
            Log::error('PaymentController: gateway initiate failed', ['error' => $e->getMessage()]);
            $payment->update(['status' => 'failed', 'result_desc' => 'Could not reach the payment gateway']);
            return response()->json(['success' => false, 'message' => 'Could not reach the payment gateway -- try again or use cash', 'data' => $payment->fresh()], 502);
        }

        // Fill in whatever identifiers the gateway returned, without
        // clobbering a status the mock path may have already settled
        // (see PaymentGatewayClient's docblock).
        $payment->update(array_filter([
            'gateway_payment_id' => $payment->gateway_payment_id ?: ($result['payment_id'] ?? null),
            'checkout_request_id' => $payment->checkout_request_id ?: ($result['checkout_request_id'] ?? null),
        ]));

        return response()->json(['success' => true, 'data' => $payment->fresh()], 201);
    }

    /**
     * POST /v1/payments/webhook -- public (no auth:sanctum, the gateway
     * is not a MARA user), signature-verified instead. Registered
     * outside the authenticated route group entirely -- see routes/api.php.
     */
    public function webhook(Request $request)
    {
        $secret = config('services.payment_gateway.webhook_secret');
        if (!$secret) {
            Log::warning('PaymentController::webhook called with no PAYMENT_WEBHOOK_SECRET configured -- rejecting');
            return response()->json(['success' => false, 'message' => 'Webhook not configured'], 503);
        }

        $signature = $request->header('X-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (!$signature || !hash_equals($expected, $signature)) {
            Log::warning('PaymentController::webhook signature mismatch', ['ip' => $request->ip()]);
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
        }

        $data = $request->validate([
            'payment_id' => 'required|string',
            'reference' => 'nullable|string',
            'status' => 'required|in:success,failed,cancelled,timeout',
            'result_code' => 'nullable|string',
            'result_desc' => 'nullable|string',
            'mpesa_receipt' => 'nullable|string',
            'amount' => 'nullable|numeric',
            'phone' => 'nullable|string',
            'paid_at' => 'nullable|date',
        ]);

        $this->settlement->settle($data);

        return response()->json(['success' => true]);
    }

    /** GET /payments/{id}/status -- the fallback poll for a webhook that hasn't arrived yet. */
    public function status(Request $request, $id)
    {
        $payment = MpesaPayment::find($id);
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if ($request->user()->hasAccessTier('driver') && $payment->initiated_by !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You do not have access to this payment.'], 403);
        }

        if (!$payment->isSettled()) {
            $this->pollAndMaybeSettle($payment);
            $payment->refresh();
        }

        return response()->json(['success' => true, 'data' => $payment]);
    }

    /** POST /payments/{id}/retry -- Manager/Director forcing a recheck (e.g. a webhook that seems lost). */
    public function retry(Request $request, $id)
    {
        $payment = MpesaPayment::find($id);
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if ($payment->isSettled()) {
            return response()->json(['success' => false, 'message' => 'This payment already settled'], 422);
        }
        $this->pollAndMaybeSettle($payment);
        return response()->json(['success' => true, 'data' => $payment->fresh()]);
    }

    private function pollAndMaybeSettle(MpesaPayment $payment): void
    {
        if (!$payment->gateway_payment_id) {
            return;
        }
        $status = $this->gateway->getPaymentStatus($payment->gateway_payment_id);
        if ($status && in_array($status['status'] ?? null, ['success', 'failed', 'cancelled', 'timeout'], true)) {
            $this->settlement->settle(array_merge($status, ['reference' => $payment->reference]));
        }
    }

    /** GET /payments -- Manager/Director monitoring list, filterable. */
    public function index(Request $request)
    {
        $query = MpesaPayment::with(['driverTripSale.customer', 'order.customer', 'initiatedBy', 'location'])
            ->where('unmatched', false);

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('initiated_by', $request->user_id);
        }

        $payments = $query->orderByDesc('created_at')->paginate($request->get('limit', 25));

        return response()->json([
            'success' => true,
            'data' => $payments->items(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    /** GET /payments/unmatched -- manual-Paybill payments the gateway routed to mara_water but that don't match a known sale. */
    public function unmatched(Request $request)
    {
        $payments = MpesaPayment::where('unmatched', true)->orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'data' => $payments]);
    }

    /** POST /payments/unmatched/{id}/assign -- links an unmatched payment to a real sale, with who/when captured as the audit trail. */
    public function assign(Request $request, $id)
    {
        $payment = MpesaPayment::where('unmatched', true)->find($id);
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Unmatched payment not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'sale_type' => 'required|in:driver_trip_sale,order',
            'sale_id' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $exists = $request->sale_type === 'driver_trip_sale'
            ? \App\Models\DriverTripSale::whereKey($request->sale_id)->exists()
            : Order::whereKey($request->sale_id)->exists();
        if (!$exists) {
            return response()->json(['success' => false, 'message' => 'That sale/order was not found'], 404);
        }

        $payment->update([
            'unmatched' => false,
            'assigned_sale_type' => $request->sale_type,
            'assigned_sale_id' => $request->sale_id,
            'assigned_by' => Auth::id(),
            'assigned_at' => now(),
            'driver_trip_sale_id' => $request->sale_type === 'driver_trip_sale' ? $request->sale_id : $payment->driver_trip_sale_id,
            'order_id' => $request->sale_type === 'order' ? $request->sale_id : $payment->order_id,
        ]);

        return response()->json(['success' => true, 'data' => $payment->fresh()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $payments = MpesaPayment::with(['driverTripSale.customer', 'order.customer', 'initiatedBy', 'location'])
            ->orderByDesc('created_at')->get();

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('M-Pesa Payments');
        $headers = ['Date', 'Reference', 'Channel', 'Location', 'Phone', 'Amount', 'Status', 'M-Pesa Receipt', 'Initiated By', 'Unmatched'];
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, 1], $h);
        }
        $row = 2;
        foreach ($payments as $p) {
            $ws->setCellValue([1, $row], $p->created_at?->toDateTimeString());
            $ws->setCellValue([2, $row], $p->reference);
            $ws->setCellValue([3, $row], $p->channel);
            $ws->setCellValue([4, $row], $p->location->name ?? '');
            $ws->setCellValue([5, $row], $p->phone);
            $ws->setCellValue([6, $row], (float) $p->amount);
            $ws->setCellValue([7, $row], $p->status);
            $ws->setCellValue([8, $row], $p->mpesa_receipt);
            $ws->setCellValue([9, $row], $p->initiatedBy->full_name ?? '');
            $ws->setCellValue([10, $row], $p->unmatched ? 'Yes' : 'No');
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="mpesa-payments.xlsx"',
        ]);
    }
}

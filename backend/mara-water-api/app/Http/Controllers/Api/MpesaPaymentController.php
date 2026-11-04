<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverTrip;
use App\Models\DriverTripSale;
use App\Models\DriverTripSaleItem;
use App\Models\MpesaPayment;
use App\Models\Order;
use App\Models\PaybillWatch;
use App\Services\MpesaStkService;
use App\Services\PaybillMatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MpesaPaymentController extends Controller
{
    public function meta(MpesaStkService $stk)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'mode' => $stk->mode(),
                'paybill' => $stk->paybill(),
                'account_prefix' => 'MARA-',
                'account_number' => 'MARA',
                'c2b_confirmation_url' => $stk->c2bConfirmationUrl(),
                'c2b_validation_url' => $stk->c2bValidationUrl(),
                'c2b_registerable' => $stk->canRegisterC2bUrls(),
            ],
        ]);
    }

    public function stk(Request $request, MpesaStkService $stk)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'order_id' => 'nullable|uuid',
            'driver_trip_sale_id' => 'nullable|uuid',
            // field_trip is the driver Log-a-Sale channel; warehouse/refill/admin are outlet flows.
            'channel' => 'nullable|in:field_trip,warehouse,refill,admin',
            'driver_trip_id' => 'required_if:channel,field_trip|nullable|uuid|exists:driver_trips,id',
            'customer_id' => 'required_if:channel,field_trip|nullable|uuid|exists:customers,id',
            'items' => 'required_if:channel,field_trip|nullable|array|min:1',
            'items.*.sku_id' => 'required_with:items|uuid|exists:skus,id',
            'items.*.qty_bales' => 'required_with:items|numeric|min:0.01',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'physical_receipt_no' => 'nullable|string|max:50',
            'physical_delivery_note_no' => 'nullable|string|max:50',
            'photo_url' => 'nullable|string|max:500',
        ]);

        $isPendingFieldTrip = ($data['channel'] ?? null) === 'field_trip'
            && ! empty($data['driver_trip_id'])
            && empty($data['driver_trip_sale_id']);

        if (! $isPendingFieldTrip && empty($data['order_id']) && empty($data['driver_trip_sale_id'])) {
            return response()->json(['success' => false, 'message' => 'A sale is required.'], 422);
        }

        try {
            if ($isPendingFieldTrip) {
                $payment = $this->startPendingFieldTripStk($request, $stk, $data);
            } elseif (! empty($data['driver_trip_sale_id'])) {
                $sale = DriverTripSale::with('trip')->findOrFail($data['driver_trip_sale_id']);
                if ($deny = $this->denyTrip($request, $sale)) {
                    return $deny;
                }
                $payment = $stk->startForTripSale($sale, $data['phone'], $request->user());
            } else {
                $order = Order::findOrFail($data['order_id']);
                $payment = $stk->startForOrder($order, $data['phone'], $request->user(), $data['channel'] ?? 'warehouse');
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        return response()->json(['success' => true, 'data' => $this->present($payment, $stk)], 201);
    }

    /**
     * Driver Log a Sale: STK first, create the trip sale only after M-Pesa confirms.
     * Same AfriGig/Daraja path managers use — previously field_trip was rejected by validation.
     */
    private function startPendingFieldTripStk(Request $request, MpesaStkService $stk, array $data): MpesaPayment
    {
        $trip = DriverTrip::with(['items.sku', 'location'])->findOrFail($data['driver_trip_id']);
        if ($request->user()->hasAccessTier('driver') && $trip->driver_id !== $request->user()->id) {
            throw new \InvalidArgumentException('You do not have access to this trip.');
        }
        if ($trip->status !== 'in_transit') {
            throw new \InvalidArgumentException('Sales can only be recorded on a trip that is in transit.');
        }

        $confirmedSold = DriverTripSaleItem::whereHas('sale', fn ($q) => $q->where('driver_trip_id', $trip->id)->whereNull('deleted_at'))
            ->selectRaw('sku_id, SUM(qty_bales) as sold')->groupBy('sku_id')->pluck('sold', 'sku_id');
        $reservedBySku = [];
        foreach (MpesaPayment::where('trip_id', $trip->id)->where('channel', 'field_trip')->where('status', 'pending')->get() as $pending) {
            foreach (($pending->pending_sale_snapshot['items'] ?? []) as $line) {
                $reservedBySku[$line['sku_id']] = ($reservedBySku[$line['sku_id']] ?? 0) + (float) $line['qty_bales'];
            }
        }

        foreach ($data['items'] as $line) {
            $tripItem = $trip->items->firstWhere('sku_id', $line['sku_id']);
            if (! $tripItem) {
                throw new \InvalidArgumentException('That item was not dispatched on this trip.');
            }
            $available = (float) $tripItem->qty_carried_bales
                - (float) ($confirmedSold[$line['sku_id']] ?? 0)
                - (float) ($reservedBySku[$line['sku_id']] ?? 0);
            if ((float) $line['qty_bales'] > $available + 0.001) {
                $skuName = $tripItem->sku->name ?? 'that item';
                throw new \InvalidArgumentException("Only {$available} bales of {$skuName} remain available on this trip.");
            }
        }

        $amount = round(collect($data['items'])->sum(fn ($l) => (float) $l['qty_bales'] * (float) $l['unit_price']), 2);
        $location = $trip->location?->code ?? $trip->location?->name;

        return $stk->startPendingFieldTrip([
            'phone' => $data['phone'],
            'amount' => $amount,
            'trip_id' => $trip->id,
            'location' => $location,
            'location_id' => $trip->location_id,
            'customer_id' => $data['customer_id'],
            'items' => $data['items'],
            'physical_receipt_no' => $data['physical_receipt_no'] ?? null,
            'physical_delivery_note_no' => $data['physical_delivery_note_no'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
        ], $request->user());
    }

    public function status(Request $request, string $id, MpesaStkService $stk)
    {
        $payment = MpesaPayment::find($id);
        if (! $payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if ($deny = $this->denyPayment($request, $payment)) {
            return $deny;
        }
        $payment = $stk->refresh($payment);

        return response()->json(['success' => true, 'data' => $this->present($payment, $stk)]);
    }

    public function recheck(Request $request, string $id, MpesaStkService $stk)
    {
        return $this->status($request, $id, $stk);
    }

    /**
     * Driver Log-a-Sale fallback: when STK UI lags but Sales already has
     * the payment, match by M-Pesa receipt / transaction code on this trip.
     */
    public function verifyReceipt(Request $request, MpesaStkService $stk)
    {
        $data = $request->validate([
            'mpesa_receipt' => 'required|string|max:32',
            'trip_id' => 'required|uuid|exists:driver_trips,id',
            'payment_id' => 'nullable|uuid',
        ]);

        $receipt = strtoupper(preg_replace('/\s+/', '', $data['mpesa_receipt']));
        $tripId = $data['trip_id'];

        $trip = DriverTrip::find($tripId);
        if ($request->user()->hasAccessTier('driver') && $trip && $trip->driver_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $q = MpesaPayment::query()
            ->where('status', 'success')
            ->whereRaw('UPPER(REPLACE(COALESCE(mpesa_receipt, \'\'), \' \', \'\')) = ?', [$receipt])
            ->where(function ($inner) use ($tripId) {
                $inner->where('trip_id', $tripId)
                    ->orWhereHas('tripSale', fn ($s) => $s->where('driver_trip_id', $tripId));
            });

        if (! empty($data['payment_id'])) {
            $q->where('id', $data['payment_id']);
        }

        $payment = $q->latest()->first();

        if (! $payment) {
            $sale = DriverTripSale::where('driver_trip_id', $tripId)
                ->whereRaw('UPPER(REPLACE(COALESCE(mpesa_reference, \'\'), \' \', \'\')) = ?', [$receipt])
                ->latest()
                ->first();
            if ($sale) {
                $payment = MpesaPayment::where('driver_trip_sale_id', $sale->id)->latest()->first();
                if (! $payment) {
                    return response()->json([
                        'success' => true,
                        'data' => [
                            'id' => null,
                            'status' => 'success',
                            'mpesa_receipt' => $receipt,
                            'driver_trip_sale_id' => $sale->id,
                            'amount' => (float) ($sale->amount ?? 0),
                            'result_desc' => 'Matched existing sale by M-Pesa reference',
                        ],
                    ]);
                }
            }
        }

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'No successful payment or sale found for that M-Pesa code on this trip',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $this->present($payment, $stk)]);
    }

    public function cash(Request $request, string $id, MpesaStkService $stk)
    {
        $payment = MpesaPayment::find($id);
        if (! $payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        if ($deny = $this->denyPayment($request, $payment)) {
            return $deny;
        }
        $stk->markCash($payment);

        return response()->json(['success' => true, 'data' => $this->present($payment->fresh(), $stk)]);
    }

    public function index(Request $request)
    {
        $limit = min(100, (int) ($request->input('limit') ?: $request->input('per_page', 40)));
        $page = $this->filtered($request)
            ->with([
                'initiator:id,first_name,last_name',
                'tripSale.customer:id,name',
                'order.customer:id,name',
                'customer:id,name,phone',
            ])
            ->latest()
            ->paginate($limit);

        return response()->json([
            'success' => true,
            // Always a plain array — the M-Pesa page does rows.map(...).
            'data' => collect($page->items())->map(fn (MpesaPayment $p) => $this->listRow($p))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** GET /payments/unmatched — payments with no linked sale. */
    public function unmatched(Request $request)
    {
        $rows = MpesaPayment::query()
            ->where(function ($q) {
                $q->where('unmatched', true)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('order_id')->whereNull('driver_trip_sale_id');
                    });
            })
            ->where('status', 'success')
            ->with(['customer:id,name,phone', 'initiator:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(fn (MpesaPayment $p) => array_merge($this->listRow($p), ['unmatched' => true]))
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** POST /payments/{id}/retry — alias of recheck for the monitoring UI. */
    public function retry(Request $request, string $id, MpesaStkService $stk)
    {
        return $this->status($request, $id, $stk);
    }

    public function export(Request $request)
    {
        $rows = $this->filtered($request)->with(['initiator:id,first_name,last_name', 'customer:id,name'])->latest()->limit(5000)->get();
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->fromArray(['When', 'Source', 'Reference', 'Account', 'Phone', 'Payer', 'Customer', 'Amount', 'Status', 'Receipt', 'Channel', 'Match', 'Location', 'By'], null, 'A1');
        $r = 2;
        foreach ($rows as $p) {
            $who = trim(($p->initiator->first_name ?? '').' '.($p->initiator->last_name ?? ''));
            $ws->fromArray([
                optional($p->created_at)->format('Y-m-d H:i'),
                $p->source ?: 'stk',
                $p->reference,
                $p->bill_ref,
                $p->phone,
                $p->payer_name,
                $p->customer?->name,
                (float) $p->amount,
                $p->status,
                $p->mpesa_receipt,
                $p->channel,
                $p->match_method,
                $p->location,
                $who,
            ], null, 'A'.$r);
            $r++;
        }
        $writer = new Xlsx($sheet);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="mpesa-payments.xlsx"',
        ]);
    }

    public function assign(Request $request, string $id, MpesaStkService $stk)
    {
        $data = $request->validate([
            'sale_type' => 'required|in:order,trip,driver_trip_sale',
            'sale_id' => 'required|uuid',
        ]);
        $saleType = $data['sale_type'] === 'driver_trip_sale' ? 'trip' : $data['sale_type'];
        $payment = MpesaPayment::find($id);
        if (! $payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        try {
            $payment = $stk->assignToSale($payment, $saleType, $data['sale_id']);
            if (\Illuminate\Support\Facades\Schema::hasColumn('mpesa_payments', 'unmatched')) {
                $payment->update(['unmatched' => false]);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($payment->fresh(), $stk)]);
    }

    public function darajaCallback(Request $request, MpesaStkService $stk)
    {
        $stk->applyDarajaCallback($request->all());

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function c2b(Request $request, MpesaStkService $stk)
    {
        try {
            $stk->applyC2b($request->all());
        } catch (\Throwable $e) {
            \Log::error('M-Pesa C2B ingest failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * Safaricom C2B ValidationURL — MARA-only Paybill: always accept.
     * Must be registered together with ConfirmationURL via Daraja RegisterURL.
     */
    public function c2bValidate(Request $request)
    {
        \Log::info('M-Pesa C2B validation', [
            'bill_ref' => $request->input('BillRefNumber'),
            'amount' => $request->input('TransAmount'),
        ]);

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }

    /** Director: register Safaricom Confirmation/Validation URLs for this Paybill. */
    public function registerC2bUrls(Request $request, MpesaStkService $stk)
    {
        if (! $request->user()?->hasAccessTier('director')) {
            return response()->json(['success' => false, 'message' => 'Only the Director can register Paybill callbacks.'], 403);
        }
        try {
            $result = $stk->registerC2bUrls();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Safaricom will now send direct Paybill payments to MARA.',
            'data' => $result,
        ]);
    }

    /**
     * Manager/Director: log a Paybill that already hit the till but never
     * reached our callback (e.g. before C2B URLs were registered).
     */
    public function logPaybill(Request $request, MpesaStkService $stk)
    {
        $data = $request->validate([
            'mpesa_receipt' => 'required|string|max:32',
            'amount' => 'required|numeric|min:1',
            'phone' => 'required|string|max:20',
            'bill_ref' => 'nullable|string|max:32',
            'payer_name' => 'nullable|string|max:80',
            'paid_at' => 'nullable|date',
        ]);

        $receipt = strtoupper(trim($data['mpesa_receipt']));
        if (MpesaPayment::where('mpesa_receipt', $receipt)->exists()) {
            return response()->json(['success' => false, 'message' => 'That M-Pesa receipt is already in the ledger.'], 422);
        }

        try {
            $phone = MpesaStkService::normalizePhone($data['phone']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $payment = $stk->applyC2b([
            'TransID' => $receipt,
            'TransAmount' => $data['amount'],
            'MSISDN' => $phone,
            'BillRefNumber' => $data['bill_ref'] ?: 'MARA',
            'FirstName' => $data['payer_name'] ?? null,
        ]);

        if (! empty($data['paid_at'])) {
            $payment->update(['paid_at' => $data['paid_at'], 'created_at' => $data['paid_at']]);
        }
        $payment->update([
            'result_desc' => 'Manual Paybill log (callback was missing)',
            'initiated_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Paybill logged',
            'data' => $this->listRow($payment->fresh()->load(['customer', 'initiator', 'tripSale.customer', 'order.customer'])),
        ], 201);
    }

    /** GET /payments/paybill — C2B rows. Drivers see only their trip/customers. */
    public function paybill(Request $request, PaybillMatchService $match)
    {
        $limit = min(100, (int) ($request->input('limit') ?: 80));
        $query = $match->driverVisibleQuery($request->user())
            ->with([
                'initiator:id,first_name,last_name',
                'tripSale.customer:id,name',
                'order.customer:id,name',
                'customer:id,name,phone',
            ])
            ->latest();
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        $page = $query->paginate($limit);
        $claimPhones = $request->user()->hasAccessTier('driver')
            ? $match->driverTodayCustomerPhones($request->user()->id)
            : [];

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(function (MpesaPayment $p) use ($claimPhones) {
                $row = $this->listRow($p);
                $row['claimable'] = (bool) $p->unmatched
                    && $p->status === 'success'
                    && ! $p->order_id
                    && ! $p->driver_trip_sale_id
                    && $claimPhones
                    && in_array(PaybillMatchService::last9((string) $p->phone), $claimPhones, true);

                return $row;
            })->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** POST /payments/paybill/watch — "Waiting for Paybill" window. */
    public function watch(Request $request, PaybillMatchService $match)
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'customer_id' => 'nullable|uuid|exists:customers,id',
            'amount' => 'nullable|numeric|min:0',
            'trip_id' => 'nullable|uuid|exists:driver_trips,id',
            'order_id' => 'nullable|uuid|exists:orders,id',
            'driver_trip_sale_id' => 'nullable|uuid|exists:driver_trip_sales,id',
            'channel' => 'nullable|in:field_trip,warehouse,refill,admin,paybill',
        ]);
        if ($request->user()->hasAccessTier('driver') && ! empty($data['trip_id'])) {
            $trip = DriverTrip::find($data['trip_id']);
            if (! $trip || $trip->driver_id !== $request->user()->id) {
                return response()->json(['success' => false, 'message' => 'You do not have access to this trip.'], 403);
            }
        }
        try {
            $watch = $match->openWatch($data, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Waiting for Paybill — tell them account MARA.',
            'data' => [
                'id' => $watch->id,
                'phone' => $watch->phone,
                'amount' => $watch->amount,
                'expires_at' => optional($watch->expires_at)?->toIso8601String(),
                'account_number' => 'MARA',
                'paybill' => app(MpesaStkService::class)->paybill(),
            ],
        ], 201);
    }

    public function watches(Request $request)
    {
        $q = PaybillWatch::query()->with('customer:id,name,phone')->latest();
        if ($request->user()->hasAccessTier('driver')) {
            $q->where('initiated_by', $request->user()->id);
        }
        $rows = $q->open()->limit(40)->get()->map(fn (PaybillWatch $w) => [
            'id' => $w->id,
            'phone' => $w->phone,
            'amount' => $w->amount,
            'expires_at' => optional($w->expires_at)?->toIso8601String(),
            'customer' => $w->customer ? ['id' => $w->customer->id, 'name' => $w->customer->name] : null,
            'trip_id' => $w->trip_id,
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function claim(Request $request, string $id, PaybillMatchService $match)
    {
        $payment = MpesaPayment::find($id);
        if (! $payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        $data = $request->validate([
            'sale_type' => 'nullable|in:order,trip,driver_trip_sale',
            'sale_id' => 'nullable|uuid',
        ]);
        try {
            $saleType = $data['sale_type'] ?? null;
            if ($saleType === 'driver_trip_sale') {
                $saleType = 'trip';
            }
            $payment = $match->claim($payment, $request->user(), $saleType, $data['sale_id'] ?? null);
            if ($payment->order_id || $payment->driver_trip_sale_id) {
                $payment->update(['unmatched' => false]);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->listRow($payment->fresh()->load(['customer', 'initiator', 'tripSale.customer', 'order.customer']))]);
    }

    public function webhook(Request $request, MpesaStkService $stk)
    {
        $secret = (string) config('mpesa.webhook_secret');
        $raw = $request->getContent();
        $given = (string) $request->header('X-Signature', '');
        $expected = hash_hmac('sha256', $raw, $secret);
        if ($secret === '' || ! hash_equals($expected, $given)) {
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
        }
        $body = json_decode($raw, true);
        if (! is_array($body)) {
            return response()->json(['success' => false, 'message' => 'Invalid body'], 422);
        }
        try {
            $stk->applyWebhook($body);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    private function filtered(Request $request)
    {
        $q = MpesaPayment::query();
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('channel')) {
            $q->where('channel', $request->channel);
        }
        if ($request->filled('source')) {
            $q->where('source', $request->source);
        }
        if ($request->filled('location')) {
            $q->where('location', $request->location);
        }
        if ($request->boolean('unmatched')) {
            $q->whereNull('order_id')->whereNull('driver_trip_sale_id');
        }
        $from = $request->input('date_from') ?: $request->input('from');
        $to = $request->input('date_to') ?: $request->input('to');
        if ($from) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('created_at', '<=', $to);
        }

        return $q;
    }

    /** Shape used by the Manager/Director M-Pesa monitoring table. */
    private function listRow(MpesaPayment $payment): array
    {
        $initiator = $payment->initiator;
        $fullName = $initiator
            ? trim(($initiator->first_name ?? '').' '.($initiator->last_name ?? ''))
            : null;
        $tripSale = $payment->tripSale;
        $order = $payment->order;

        $customer = $payment->customer;

        return [
            'id' => $payment->id,
            'channel' => $payment->channel,
            'source' => $payment->source ?: 'stk',
            'reference' => $payment->reference,
            'bill_ref' => $payment->bill_ref,
            'phone' => $payment->phone,
            'payer_name' => $payment->payer_name,
            'amount' => (string) $payment->amount,
            'status' => $payment->status,
            'result_desc' => $payment->result_desc,
            'mpesa_receipt' => $payment->mpesa_receipt,
            'match_method' => $payment->match_method,
            'unmatched' => (bool) $payment->unmatched,
            'created_at' => optional($payment->created_at)?->toIso8601String(),
            'paid_at' => optional($payment->paid_at)?->toIso8601String(),
            'location' => is_string($payment->location) && $payment->location !== ''
                ? ['name' => $payment->location]
                : null,
            'initiated_by' => $fullName ? ['full_name' => $fullName] : null,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
            ] : null,
            'driver_trip_sale' => $tripSale ? [
                'id' => $tripSale->id,
                'customer' => $tripSale->customer ? ['name' => $tripSale->customer->name] : null,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'order_no' => $order->order_no ?? $order->order_number ?? null,
                'customer' => $order->customer ? ['name' => $order->customer->name] : null,
            ] : null,
            'trip_id' => $payment->trip_id,
        ];
    }

    private function present(MpesaPayment $payment, MpesaStkService $stk): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'phone' => $payment->phone,
            'reference' => $payment->reference,
            'result_desc' => $payment->result_desc,
            'mpesa_receipt' => $payment->mpesa_receipt,
            'channel' => $payment->channel,
            'location' => $payment->location,
            'order_id' => $payment->order_id,
            'driver_trip_sale_id' => $payment->driver_trip_sale_id,
            'source' => $payment->source ?: 'stk',
            'bill_ref' => $payment->bill_ref,
            'match_method' => $payment->match_method,
            'unmatched' => (bool) $payment->unmatched,
            'paybill' => $stk->paybill(),
            'account_number' => 'MARA',
            'mode' => $stk->mode(),
            'paid_at' => optional($payment->paid_at)?->toIso8601String(),
        ];
    }

    private function denyPayment(Request $request, MpesaPayment $payment)
    {
        if ($request->user()->hasAccessTier('investor')) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        if ($request->user()->hasAccessTier('driver') && $payment->initiated_by !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function denyTrip(Request $request, DriverTripSale $sale)
    {
        if ($request->user()->hasAccessTier('driver') && $sale->trip && $sale->trip->driver_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }
}

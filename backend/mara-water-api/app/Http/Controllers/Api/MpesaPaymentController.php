<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverTrip;
use App\Models\DriverTripSale;
use App\Models\DriverTripSaleItem;
use App\Models\MpesaPayment;
use App\Models\Order;
use App\Services\MpesaStkService;
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
        $rows = $this->filtered($request)->with('initiator:id,first_name,last_name')->latest()->limit(5000)->get();
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->fromArray(['When', 'Reference', 'Phone', 'Amount', 'Status', 'Receipt', 'Channel', 'Location', 'By'], null, 'A1');
        $r = 2;
        foreach ($rows as $p) {
            $who = trim(($p->initiator->first_name ?? '').' '.($p->initiator->last_name ?? ''));
            $ws->fromArray([
                optional($p->created_at)->format('Y-m-d H:i'),
                $p->reference,
                $p->phone,
                (float) $p->amount,
                $p->status,
                $p->mpesa_receipt,
                $p->channel,
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
        $stk->applyC2b($request->all());

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
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

        return [
            'id' => $payment->id,
            'channel' => $payment->channel,
            'reference' => $payment->reference,
            'phone' => $payment->phone,
            'amount' => (string) $payment->amount,
            'status' => $payment->status,
            'result_desc' => $payment->result_desc,
            'mpesa_receipt' => $payment->mpesa_receipt,
            'created_at' => optional($payment->created_at)?->toIso8601String(),
            'location' => is_string($payment->location) && $payment->location !== ''
                ? ['name' => $payment->location]
                : null,
            'initiated_by' => $fullName ? ['full_name' => $fullName] : null,
            'driver_trip_sale' => $tripSale ? [
                'id' => $tripSale->id,
                'customer' => $tripSale->customer ? ['name' => $tripSale->customer->name] : null,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'order_no' => $order->order_no ?? $order->order_number ?? null,
                'customer' => $order->customer ? ['name' => $order->customer->name] : null,
            ] : null,
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
            'paybill' => $stk->paybill(),
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

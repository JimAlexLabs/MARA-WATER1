<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DriverTripSale;
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
            'channel' => 'nullable|in:warehouse,refill,admin',
        ]);
        if (empty($data['order_id']) && empty($data['driver_trip_sale_id'])) {
            return response()->json(['success' => false, 'message' => 'A sale is required.'], 422);
        }

        try {
            if (! empty($data['driver_trip_sale_id'])) {
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

        return response()->json(['success' => true, 'data' => $this->present($payment, $stk)]);
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
        $q = $this->filtered($request)->with('initiator:id,first_name,last_name');
        $rows = $q->latest()->paginate(min(100, (int) $request->input('per_page', 40)));

        return response()->json(['success' => true, 'data' => $rows]);
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
            'sale_type' => 'required|in:order,trip',
            'sale_id' => 'required|uuid',
        ]);
        $payment = MpesaPayment::find($id);
        if (! $payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
        }
        try {
            $payment = $stk->assignToSale($payment, $data['sale_type'], $data['sale_id']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $this->present($payment, $stk)]);
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
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->to);
        }

        return $q;
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

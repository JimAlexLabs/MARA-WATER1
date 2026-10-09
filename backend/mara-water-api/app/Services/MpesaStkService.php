<?php

namespace App\Services;

use App\Http\Controllers\Api\InventoryController;
use App\Models\DriverTripSale;
use App\Models\MpesaPayment;
use App\Models\Order;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MpesaStkService
{
    public function mode(): string
    {
        if (config('mpesa.gateway_url') && config('mpesa.gateway_key')) {
            return 'gateway';
        }
        $hasDaraja = config('mpesa.consumer_key') && config('mpesa.consumer_secret')
            && config('mpesa.shortcode') && config('mpesa.passkey');
        $mock = config('mpesa.mock');
        $forceMock = $mock === true || $mock === 'true' || $mock === '1';
        if ($hasDaraja && ! $forceMock) {
            return 'daraja';
        }

        return 'mock';
    }

    public function paybill(): ?string
    {
        $bill = config('mpesa.paybill') ?: config('mpesa.shortcode');

        return $bill ? (string) $bill : null;
    }

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '254'.substr($digits, 1);
        }
        if (str_starts_with($digits, '254') && strlen($digits) === 12 && preg_match('/^254(7|1)\d{8}$/', $digits)) {
            return $digits;
        }
        throw new \InvalidArgumentException('Enter a Safaricom or Airtel number, for example 0712 345 678.');
    }

    public function startForTripSale(DriverTripSale $sale, string $phone, User $user): MpesaPayment
    {
        $sale->loadMissing('trip.location');
        $location = $sale->trip?->location?->code ?? $sale->trip?->location?->name;

        return $this->start([
            'driver_trip_sale_id' => $sale->id,
            'order_id' => null,
            'amount' => (float) $sale->amount,
            'phone' => $phone,
            'channel' => 'field_trip',
            'trip_id' => $sale->driver_trip_id,
            'location' => $location,
            'description' => 'Field sale',
        ], $user);
    }

    public function startForOrder(Order $order, string $phone, User $user, string $channel = 'warehouse'): MpesaPayment
    {
        $order->loadMissing('location');

        return $this->start([
            'driver_trip_sale_id' => null,
            'order_id' => $order->id,
            'amount' => (float) $order->total_amount,
            'phone' => $phone,
            'channel' => in_array($channel, ['warehouse', 'refill', 'admin'], true) ? $channel : 'warehouse',
            'trip_id' => null,
            'location' => $order->location?->code ?? $order->location?->name,
            'description' => 'Outlet sale '.$order->order_no,
        ], $user);
    }

    public function start(array $input, User $user): MpesaPayment
    {
        $phone = self::normalizePhone($input['phone']);
        $amount = (int) round((float) $input['amount']);
        if ($amount < 1) {
            throw new \InvalidArgumentException('Amount must be at least KES 1.');
        }

        $existing = MpesaPayment::query()
            ->when($input['order_id'] ?? null, fn ($q, $id) => $q->where('order_id', $id))
            ->when($input['driver_trip_sale_id'] ?? null, fn ($q, $id) => $q->where('driver_trip_sale_id', $id))
            ->where('status', 'pending')
            ->latest()
            ->first();
        if ($existing) {
            return $existing;
        }

        $saleKey = $input['order_id'] ?? $input['driver_trip_sale_id'];
        $attempt = MpesaPayment::query()
            ->when($input['order_id'] ?? null, fn ($q, $id) => $q->where('order_id', $id))
            ->when($input['driver_trip_sale_id'] ?? null, fn ($q, $id) => $q->where('driver_trip_sale_id', $id))
            ->count() + 1;
        $reference = $this->referenceFor($saleKey);

        $payment = MpesaPayment::create([
            'order_id' => $input['order_id'] ?? null,
            'driver_trip_sale_id' => $input['driver_trip_sale_id'] ?? null,
            'reference' => $reference,
            'phone' => $phone,
            'amount' => $amount,
            'status' => 'pending',
            'initiated_by' => $user->id,
            'channel' => $input['channel'],
            'trip_id' => $input['trip_id'] ?? null,
            'location' => isset($input['location']) ? substr((string) $input['location'], 0, 40) : null,
            'idempotency_key' => 'stk-'.$saleKey.'-'.$attempt,
        ]);

        try {
            $this->dispatch($payment, $input['description'] ?? 'Mara Water sale');
        } catch (\Throwable $e) {
            $payment->update([
                'status' => 'failed',
                'result_desc' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $payment->fresh();
    }

    public function refresh(MpesaPayment $payment): MpesaPayment
    {
        if ($payment->status !== 'pending') {
            return $payment;
        }

        if ($this->mode() === 'mock') {
            if ($payment->created_at->lt(now()->subSeconds(4))) {
                $this->applyResult($payment, $this->mockResult($payment));
            }

            return $payment->fresh();
        }

        if ($payment->created_at->gt(now()->subSeconds(8))) {
            return $payment;
        }

        if ($this->mode() === 'gateway' && $payment->gateway_payment_id) {
            $res = $this->gatewayHttp()->get($this->gatewayUrl().'?payment_id='.$payment->gateway_payment_id);
            if ($res->successful()) {
                $body = $res->json();
                if (($body['status'] ?? 'pending') !== 'pending') {
                    $this->applyResult($payment, [
                        'status' => $body['status'],
                        'result_code' => (string) ($body['result_code'] ?? ''),
                        'result_desc' => $body['result_desc'] ?? $body['status'],
                        'mpesa_receipt' => $body['mpesa_receipt'] ?? null,
                        'amount' => $body['amount'] ?? $payment->amount,
                    ]);
                }
            }

            return $payment->fresh();
        }

        if ($this->mode() === 'daraja' && $payment->checkout_request_id) {
            $queried = $this->queryDaraja($payment);
            if ($queried) {
                $this->applyResult($payment, $queried);
            }
        }

        return $payment->fresh();
    }

    public function applyWebhook(array $body): MpesaPayment
    {
        $payment = null;
        if (! empty($body['payment_id'])) {
            $payment = MpesaPayment::where('gateway_payment_id', $body['payment_id'])->first();
        }
        if (! $payment && ! empty($body['reference'])) {
            $payment = MpesaPayment::where('reference', $body['reference'])->latest()->first();
        }

        if (! $payment && ($body['status'] ?? '') === 'success') {
            $payment = MpesaPayment::create([
                'reference' => substr((string) ($body['reference'] ?? 'UNMATCHED'), 0, 12),
                'gateway_payment_id' => $body['payment_id'] ?? null,
                'phone' => substr(preg_replace('/\D+/', '', (string) ($body['phone'] ?? '254700000000')), 0, 15),
                'amount' => (float) ($body['amount'] ?? 0),
                'status' => 'pending',
                'channel' => 'unmatched',
                'idempotency_key' => 'wh-'.($body['payment_id'] ?? Str::uuid()),
                'mpesa_receipt' => $body['mpesa_receipt'] ?? null,
            ]);
        }

        if (! $payment) {
            throw new \RuntimeException('Payment not found.');
        }

        $this->applyResult($payment, [
            'status' => $body['status'] ?? 'failed',
            'result_code' => (string) ($body['result_code'] ?? ''),
            'result_desc' => $body['result_desc'] ?? '',
            'mpesa_receipt' => $body['mpesa_receipt'] ?? null,
            'amount' => $body['amount'] ?? $payment->amount,
        ]);

        return $payment->fresh();
    }

    public function applyDarajaCallback(array $payload): void
    {
        $stk = $payload['Body']['stkCallback'] ?? null;
        if (! $stk) {
            return;
        }
        $payment = MpesaPayment::where('checkout_request_id', $stk['CheckoutRequestID'] ?? '')->first();
        if (! $payment) {
            Log::warning('M-Pesa callback for unknown checkout', ['checkout' => $stk['CheckoutRequestID'] ?? null]);

            return;
        }
        $code = (int) ($stk['ResultCode'] ?? 1);
        $meta = [];
        foreach ($stk['CallbackMetadata']['Item'] ?? [] as $item) {
            $meta[$item['Name'] ?? ''] = $item['Value'] ?? null;
        }
        $this->applyResult($payment, [
            'status' => $this->statusFromCode($code),
            'result_code' => (string) $code,
            'result_desc' => (string) ($stk['ResultDesc'] ?? ''),
            'mpesa_receipt' => $meta['MpesaReceiptNumber'] ?? null,
            'amount' => $meta['Amount'] ?? $payment->amount,
        ]);
    }

    public function applyC2b(array $body): MpesaPayment
    {
        $ref = strtoupper(trim((string) ($body['BillRefNumber'] ?? '')));
        $receipt = $body['TransID'] ?? null;
        if ($receipt && MpesaPayment::where('mpesa_receipt', $receipt)->exists()) {
            return MpesaPayment::where('mpesa_receipt', $receipt)->first();
        }

        $payment = $ref !== '' ? MpesaPayment::where('reference', substr($ref, 0, 12))->latest()->first() : null;
        if (! $payment) {
            $payment = MpesaPayment::create([
                'reference' => substr($ref !== '' ? $ref : 'UNMATCHED', 0, 12),
                'phone' => substr(preg_replace('/\D+/', '', (string) ($body['MSISDN'] ?? '')), 0, 15) ?: '254700000000',
                'amount' => (float) ($body['TransAmount'] ?? 0),
                'status' => 'pending',
                'channel' => str_starts_with($ref, 'MARA-') ? 'paybill' : 'unmatched',
                'idempotency_key' => 'c2b-'.($receipt ?: Str::uuid()),
                'mpesa_receipt' => $receipt,
            ]);
        }

        $this->applyResult($payment, [
            'status' => 'success',
            'result_code' => '0',
            'result_desc' => 'Paybill payment',
            'mpesa_receipt' => $receipt,
            'amount' => $body['TransAmount'] ?? $payment->amount,
        ]);

        return $payment->fresh();
    }

    public function markCash(MpesaPayment $payment): void
    {
        if ($payment->status === 'pending') {
            $payment->update(['status' => 'cancelled', 'result_desc' => 'Switched to cash']);
        }
        if ($payment->order_id) {
            $order = Order::find($payment->order_id);
            if ($order && $order->payment_status !== 'paid') {
                $order->update(['payment_method' => 'cash', 'payment_status' => 'paid', 'status' => 'delivered']);
                $this->postOrderStock($order);
            }
        }
        if ($payment->driver_trip_sale_id) {
            DriverTripSale::where('id', $payment->driver_trip_sale_id)->update([
                'payment_method' => 'cash',
                'payment_status' => 'paid',
            ]);
        }
    }

    public function assignToSale(MpesaPayment $payment, string $saleType, string $saleId): MpesaPayment
    {
        if ($payment->order_id || $payment->driver_trip_sale_id) {
            throw new \InvalidArgumentException('This payment is already linked to a sale.');
        }
        if ($payment->status !== 'success') {
            throw new \InvalidArgumentException('Only a confirmed payment can be assigned.');
        }
        if ($saleType === 'order') {
            $order = Order::findOrFail($saleId);
            $payment->update(['order_id' => $order->id, 'channel' => $payment->channel === 'unmatched' ? 'warehouse' : $payment->channel]);
            $this->markSalePaid($payment->fresh());
        } else {
            $sale = DriverTripSale::findOrFail($saleId);
            $payment->update(['driver_trip_sale_id' => $sale->id, 'trip_id' => $sale->driver_trip_id, 'channel' => 'field_trip']);
            $this->markSalePaid($payment->fresh());
        }

        return $payment->fresh();
    }

    private function dispatch(MpesaPayment $payment, string $description): void
    {
        $mode = $this->mode();
        if ($mode === 'mock') {
            $payment->update([
                'checkout_request_id' => 'ws_CO_mock_'.$payment->id,
                'merchant_request_id' => 'mock-'.$payment->id,
                'result_desc' => 'Test mode: no prompt was sent to the phone.',
            ]);

            return;
        }

        if ($mode === 'gateway') {
            $res = $this->gatewayHttp()->post($this->gatewayUrl(), [
                'reference' => $payment->reference,
                'phone' => $payment->phone,
                'amount' => (int) $payment->amount,
                'description' => $description,
                'idempotency_key' => $payment->idempotency_key,
                'metadata' => ['app_id' => 'mara_water', 'channel' => $payment->channel],
            ]);
            if (! $res->successful()) {
                throw new \RuntimeException('Payment gateway rejected the request.');
            }
            $body = $res->json();
            $payment->update([
                'gateway_payment_id' => $body['payment_id'] ?? null,
                'checkout_request_id' => $body['checkout_request_id'] ?? null,
                'status' => 'pending',
            ]);

            return;
        }

        $token = $this->darajaToken();
        $timestamp = now()->format('YmdHis');
        $shortcode = (string) config('mpesa.shortcode');
        $password = base64_encode($shortcode.config('mpesa.passkey').$timestamp);
        $callback = config('mpesa.callback_url') ?: rtrim(config('app.url'), '/').'/api/v1/payments/daraja/callback';
        $res = Http::withToken($token)
            ->acceptJson()
            ->timeout(25)
            ->post($this->darajaBase().'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) $payment->amount,
                'PartyA' => $payment->phone,
                'PartyB' => $shortcode,
                'PhoneNumber' => $payment->phone,
                'CallBackURL' => $callback,
                'AccountReference' => $payment->reference,
                'TransactionDesc' => substr($description, 0, 13),
            ]);
        $body = $res->json() ?? [];
        Log::info('M-Pesa STK response', [
            'payment_id' => $payment->id,
            'response_code' => $body['ResponseCode'] ?? $res->status(),
        ]);
        if (! $res->successful() || (string) ($body['ResponseCode'] ?? '1') !== '0') {
            throw new \RuntimeException($body['errorMessage'] ?? $body['ResponseDescription'] ?? 'Safaricom did not accept the payment request.');
        }
        $payment->update([
            'merchant_request_id' => $body['MerchantRequestID'] ?? null,
            'checkout_request_id' => $body['CheckoutRequestID'] ?? null,
            'gateway_payment_id' => $body['CheckoutRequestID'] ?? null,
        ]);
    }

    private function queryDaraja(MpesaPayment $payment): ?array
    {
        try {
            $timestamp = now()->format('YmdHis');
            $shortcode = (string) config('mpesa.shortcode');
            $password = base64_encode($shortcode.config('mpesa.passkey').$timestamp);
            $res = Http::withToken($this->darajaToken())
                ->acceptJson()
                ->timeout(20)
                ->post($this->darajaBase().'/mpesa/stkpushquery/v1/query', [
                    'BusinessShortCode' => $shortcode,
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $payment->checkout_request_id,
                ]);
            $body = $res->json() ?? [];
            if (! isset($body['ResultCode'])) {
                return null;
            }
            $code = (int) $body['ResultCode'];
            if (in_array($code, [4999, 1], true) && str_contains(strtolower((string) ($body['ResultDesc'] ?? '')), 'being processed')) {
                return null;
            }

            return [
                'status' => $this->statusFromCode($code),
                'result_code' => (string) $code,
                'result_desc' => (string) ($body['ResultDesc'] ?? ''),
                'mpesa_receipt' => null,
                'amount' => $payment->amount,
            ];
        } catch (\Throwable $e) {
            Log::warning('M-Pesa query failed', ['payment_id' => $payment->id]);

            return null;
        }
    }

    private function applyResult(MpesaPayment $payment, array $result): void
    {
        $status = $result['status'] ?? 'failed';
        if (! in_array($status, ['success', 'failed', 'cancelled', 'timeout', 'pending'], true)) {
            $status = 'failed';
        }
        if ($payment->status === 'success' && $status === 'success') {
            if (! $payment->mpesa_receipt && ! empty($result['mpesa_receipt'])) {
                $payment->update(['mpesa_receipt' => $result['mpesa_receipt']]);
                $this->writeReceiptOnSale($payment->fresh());
            }

            return;
        }
        if ($payment->status === 'success') {
            return;
        }
        if ($status === 'pending') {
            return;
        }

        $receipt = $result['mpesa_receipt'] ?? null;
        if ($receipt && MpesaPayment::where('mpesa_receipt', $receipt)->where('id', '!=', $payment->id)->exists()) {
            $status = 'failed';
            $result['result_desc'] = 'Receipt already used on another payment.';
            $receipt = null;
        }

        $payment->update([
            'status' => $status,
            'result_code' => $result['result_code'] ?? null,
            'result_desc' => substr((string) ($result['result_desc'] ?? ''), 0, 255),
            'mpesa_receipt' => $receipt ?: $payment->mpesa_receipt,
            'paid_at' => $status === 'success' ? now() : null,
        ]);

        if ($status === 'success') {
            $this->markSalePaid($payment->fresh());
        } elseif (in_array($status, ['failed', 'cancelled', 'timeout'], true)) {
            $this->voidUnpaidTripSale($payment->fresh());
        }
    }

    private function voidUnpaidTripSale(MpesaPayment $payment): void
    {
        if (! $payment->driver_trip_sale_id) {
            return;
        }
        $sale = DriverTripSale::find($payment->driver_trip_sale_id);
        if (! $sale || $sale->payment_status === 'paid') {
            return;
        }
        $sale->items()->delete();
        $sale->delete();
    }

    private function markSalePaid(MpesaPayment $payment): void
    {
        if ($payment->order_id) {
            $order = Order::find($payment->order_id);
            if ($order) {
                $order->update([
                    'payment_method' => 'mpesa',
                    'payment_status' => 'paid',
                    'payment_reference' => $payment->mpesa_receipt ?: $order->payment_reference,
                    'status' => 'delivered',
                ]);
                $this->postOrderStock($order->fresh());
            }
        }
        if ($payment->driver_trip_sale_id) {
            DriverTripSale::where('id', $payment->driver_trip_sale_id)->update([
                'payment_method' => 'mpesa',
                'payment_status' => 'paid',
                'mpesa_reference' => $payment->mpesa_receipt,
            ]);
        }
    }

    private function writeReceiptOnSale(MpesaPayment $payment): void
    {
        if ($payment->order_id && $payment->mpesa_receipt) {
            Order::where('id', $payment->order_id)->update(['payment_reference' => $payment->mpesa_receipt]);
        }
        if ($payment->driver_trip_sale_id && $payment->mpesa_receipt) {
            DriverTripSale::where('id', $payment->driver_trip_sale_id)->update(['mpesa_reference' => $payment->mpesa_receipt]);
        }
    }

    public function postOrderStock(Order $order): void
    {
        if ($order->stock_posted) {
            return;
        }
        $order->loadMissing('items.sku');
        $inventory = new InventoryController();
        foreach ($order->items as $item) {
            $sku = $item->sku ?: Sku::find($item->sku_id);
            $returned = (int) ($item->qty_returned ?? 0);
            if ($returned > 0 && $order->warehouse_id) {
                $inventory->recordMove([
                    'move_type' => 'return',
                    'item_type' => 'sku',
                    'sku_id' => $item->sku_id,
                    'warehouse_to_id' => $order->warehouse_id,
                    'qty' => $returned,
                    'uom' => $sku->unit ?? 'BOTTLE',
                    'ref_entity' => 'order',
                    'ref_id' => $order->id,
                ]);
            }
            $net = (int) $item->qty - $returned;
            if ($net > 0 && $order->warehouse_id) {
                $inventory->deductStock($item->sku_id, $sku, $order->warehouse_id, $net, 'order', $order->id);
            }
        }
        $order->update(['stock_posted' => true]);
    }

    private function mockResult(MpesaPayment $payment): array
    {
        $phone = $payment->phone;
        if (str_ends_with($phone, '0000')) {
            return ['status' => 'cancelled', 'result_code' => '1032', 'result_desc' => 'Customer cancelled the request.', 'amount' => $payment->amount];
        }
        if (str_ends_with($phone, '1111')) {
            return ['status' => 'timeout', 'result_code' => '1037', 'result_desc' => 'No response from the customer phone.', 'amount' => $payment->amount];
        }
        if (str_ends_with($phone, '2222')) {
            return ['status' => 'failed', 'result_code' => '1', 'result_desc' => 'Insufficient M-Pesa balance.', 'amount' => $payment->amount];
        }

        return [
            'status' => 'success',
            'result_code' => '0',
            'result_desc' => 'Test payment confirmed. No money moved.',
            'mpesa_receipt' => 'TEST'.strtoupper(substr(str_replace('-', '', $payment->id), 0, 6)),
            'amount' => $payment->amount,
        ];
    }

    private function statusFromCode(int $code): string
    {
        return match ($code) {
            0 => 'success',
            1032 => 'cancelled',
            1037, 1031 => 'timeout',
            default => 'failed',
        };
    }

    private function referenceFor(string $saleKey): string
    {
        $compact = strtoupper(substr(str_replace('-', '', $saleKey), 0, 7));

        return 'MARA-'.$compact;
    }

    private function darajaBase(): string
    {
        return config('mpesa.env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    private function darajaToken(): string
    {
        return Cache::remember('mpesa_daraja_token_'.config('mpesa.env'), 3000, function () {
            $res = Http::withBasicAuth((string) config('mpesa.consumer_key'), (string) config('mpesa.consumer_secret'))
                ->acceptJson()
                ->timeout(20)
                ->get($this->darajaBase().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
            $token = $res->json('access_token');
            if (! $token) {
                throw new \RuntimeException('Could not sign in to Safaricom.');
            }

            return $token;
        });
    }

    private function gatewayUrl(): string
    {
        return rtrim((string) config('mpesa.gateway_url'), '/');
    }

    private function gatewayHttp(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::withToken((string) config('mpesa.gateway_key'))
            ->acceptJson()
            ->timeout(25);
        $anon = config('mpesa.gateway_anon_key');
        if ($anon) {
            $req = $req->withHeaders(['apikey' => $anon]);
        }

        return $req;
    }
}

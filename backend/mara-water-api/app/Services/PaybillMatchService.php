<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DriverTrip;
use App\Models\DriverTripSale;
use App\Models\MpesaPayment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaybillWatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Direct Paybill (C2B): account number stays MARA for everyone.
 * The unique person id is the payer MSISDN. Match strictly; ambiguity
 * stays unmatched for a human to assign.
 */
class PaybillMatchService
{
    public static function last9(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($d, '254') && strlen($d) >= 12) {
            $d = substr($d, 3);
        } elseif (str_starts_with($d, '0') && strlen($d) >= 10) {
            $d = substr($d, 1);
        }

        return substr($d, -9);
    }

    public static function normalizeLoose(string $phone): string
    {
        try {
            return MpesaStkService::normalizePhone($phone);
        } catch (\InvalidArgumentException $e) {
            $d = preg_replace('/\D+/', '', $phone) ?? '';

            return substr($d, 0, 15);
        }
    }

    public function customersByPhone(string $phone): \Illuminate\Support\Collection
    {
        $last9 = self::last9($phone);
        if (strlen($last9) < 9) {
            return collect();
        }

        return Customer::query()
            ->whereNotNull('phone')
            ->where('phone', 'like', '%'.$last9)
            ->get()
            ->filter(fn (Customer $c) => self::last9((string) $c->phone) === $last9)
            ->values();
    }

    /**
     * MARA-only Paybill: capture every C2B confirmation. BillRef is stored
     * as typed (MARA / Mara Water / anything).
     */
    public function ingestC2b(array $body): MpesaPayment
    {
        $receipt = $body['TransID'] ?? $body['transactionId'] ?? $body['mpesa_receipt'] ?? null;
        if ($receipt) {
            $existing = MpesaPayment::where('mpesa_receipt', $receipt)->first();
            if ($existing) {
                return $existing;
            }
        }

        $rawPhone = (string) ($body['MSISDN'] ?? $body['phone'] ?? '');
        $phone = self::normalizeLoose($rawPhone) ?: '254700000000';
        $amount = (float) ($body['TransAmount'] ?? $body['amount'] ?? 0);
        $billRef = strtoupper(trim((string) ($body['BillRefNumber'] ?? $body['BillRef'] ?? $body['reference'] ?? '')));
        $payerName = trim((string) ($body['FirstName'] ?? $body['payer_name'] ?? ''));
        if ($payerName !== '' && ! empty($body['LastName'])) {
            $payerName = trim($payerName.' '.$body['LastName']);
        }

        $payment = MpesaPayment::create([
            'reference' => substr($billRef !== '' ? $billRef : 'MARA', 0, 20),
            'bill_ref' => $billRef !== '' ? substr($billRef, 0, 32) : 'MARA',
            'phone' => substr($phone, 0, 15),
            'payer_name' => $payerName !== '' ? substr($payerName, 0, 80) : null,
            'amount' => $amount,
            'status' => 'pending',
            'channel' => 'paybill',
            'source' => 'c2b',
            'unmatched' => true,
            'idempotency_key' => 'c2b-'.($receipt ?: (string) Str::uuid()),
            'mpesa_receipt' => $receipt,
        ]);

        $this->match($payment);

        app(MpesaStkService::class)->applyC2bResult($payment->fresh(), [
            'status' => 'success',
            'result_code' => '0',
            'result_desc' => 'Paybill payment',
            'mpesa_receipt' => $receipt,
            'amount' => $amount,
        ]);

        return $payment->fresh();
    }

    public function match(MpesaPayment $payment): MpesaPayment
    {
        if (! DB::transactionLevel()) {
            return DB::transaction(fn () => $this->match($payment));
        }

        $watchHit = $this->matchWatch($payment);
        if ($watchHit) {
            return $watchHit;
        }

        $customers = $this->customersByPhone($payment->phone);
        if ($customers->count() === 1) {
            $customer = $customers->first();
            $payment->customer_id = $customer->id;
            $this->attachTripContext($payment, $customer->id);
            $saleHit = $this->matchUnpaidSale($payment, $customer);
            if ($saleHit) {
                return $saleHit;
            }
            $payment->unmatched = ! $payment->order_id && ! $payment->driver_trip_sale_id;
            $payment->match_method = $payment->match_method ?: 'phone';
            $payment->save();
            $this->notify($payment, $customer->name);

            return $payment;
        }

        if ($customers->count() > 1) {
            $payment->unmatched = true;
            $payment->match_method = null;
            $payment->save();
            Log::info('paybill_ambiguous_customer', [
                'payment_id' => $payment->id,
                'phone' => $payment->phone,
                'customer_ids' => $customers->pluck('id'),
            ]);

            return $payment;
        }

        $payment->unmatched = true;
        $payment->save();

        return $payment;
    }

    private function matchWatch(MpesaPayment $payment): ?MpesaPayment
    {
        $last9 = self::last9($payment->phone);
        if (strlen($last9) < 9) {
            return null;
        }

        $watches = PaybillWatch::query()
            ->open()
            ->where('phone', 'like', '%'.$last9)
            ->lockForUpdate()
            ->get()
            ->filter(fn (PaybillWatch $w) => self::last9($w->phone) === $last9)
            ->values();

        if ($watches->isEmpty()) {
            return null;
        }

        $amounted = $watches->filter(function (PaybillWatch $w) use ($payment) {
            if ($w->amount === null) {
                return true;
            }

            return abs((float) $w->amount - (float) $payment->amount) <= 1.0;
        });

        if ($amounted->count() !== 1) {
            return null;
        }

        /** @var PaybillWatch $watch */
        $watch = $amounted->first();
        $payment->customer_id = $watch->customer_id;
        $payment->trip_id = $watch->trip_id ?: $payment->trip_id;
        $payment->initiated_by = $watch->initiated_by;
        $payment->order_id = $watch->order_id ?: $payment->order_id;
        $payment->driver_trip_sale_id = $watch->driver_trip_sale_id ?: $payment->driver_trip_sale_id;
        $payment->channel = $watch->channel ?: ($watch->trip_id ? 'field_trip' : 'paybill');
        $payment->match_method = 'watch';
        $payment->unmatched = ! $payment->order_id && ! $payment->driver_trip_sale_id;
        $payment->save();

        $watch->update([
            'status' => 'matched',
            'matched_payment_id' => $payment->id,
        ]);

        if ($payment->unmatched) {
            $saleHit = $this->matchUnpaidSale($payment, $watch->customer_id ? Customer::find($watch->customer_id) : null);
            if ($saleHit) {
                $payment = $saleHit;
            }
        }

        $name = $watch->customer?->name;
        $this->notify($payment, $name);

        return $payment;
    }

    private function matchUnpaidSale(MpesaPayment $payment, ?Customer $customer): ?MpesaPayment
    {
        if (! $customer) {
            return null;
        }

        $amount = (float) $payment->amount;
        $since = now()->startOfDay()->subHours(6);

        $tripSales = DriverTripSale::query()
            ->where('customer_id', $customer->id)
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid');
            })
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (DriverTripSale $s) => abs((float) $s->amount - $amount) <= 1.0)
            ->values();

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'paid');
            })
            ->get()
            ->filter(fn (Order $o) => abs((float) $o->total_amount - $amount) <= 1.0)
            ->values();

        $total = $tripSales->count() + $orders->count();
        if ($total !== 1) {
            return null;
        }

        if ($tripSales->count() === 1) {
            $sale = $tripSales->first();
            $payment->driver_trip_sale_id = $sale->id;
            $payment->trip_id = $sale->driver_trip_id;
            $payment->channel = 'field_trip';
            $payment->match_method = $payment->match_method === 'watch' ? 'watch' : 'sale_amount';
            $payment->unmatched = false;
            $payment->save();

            return $payment;
        }

        $order = $orders->first();
        $payment->order_id = $order->id;
        $payment->channel = in_array($payment->channel, ['paybill', 'unmatched', null], true) ? 'warehouse' : $payment->channel;
        $payment->match_method = $payment->match_method === 'watch' ? 'watch' : 'sale_amount';
        $payment->unmatched = false;
        $payment->save();

        return $payment;
    }

    private function attachTripContext(MpesaPayment $payment, string $customerId): void
    {
        $trip = DriverTrip::query()
            ->where('status', 'in_transit')
            ->whereHas('sales', fn ($q) => $q->where('customer_id', $customerId))
            ->orderByDesc('updated_at')
            ->first();

        if (! $trip) {
            $trip = DriverTrip::query()
                ->whereDate('trip_date', now()->toDateString())
                ->whereHas('sales', fn ($q) => $q->where('customer_id', $customerId))
                ->orderByDesc('updated_at')
                ->first();
        }

        if ($trip) {
            $payment->trip_id = $trip->id;
            $payment->initiated_by = $payment->initiated_by ?: $trip->driver_id;
            if ($payment->channel === 'paybill') {
                $payment->channel = 'field_trip';
            }
        }
    }

    public function openWatch(array $input, $user): PaybillWatch
    {
        $phone = self::normalizeLoose((string) $input['phone']);
        if (strlen(self::last9($phone)) < 9) {
            throw new \InvalidArgumentException('Enter the customer phone they will pay from.');
        }

        $amount = isset($input['amount']) && $input['amount'] !== '' && $input['amount'] !== null
            ? round((float) $input['amount'], 2)
            : null;
        if ($amount !== null && $amount < 1) {
            throw new \InvalidArgumentException('Amount must be at least KES 1, or leave it blank.');
        }

        PaybillWatch::query()
            ->open()
            ->where('initiated_by', $user->id)
            ->where('phone', $phone)
            ->update(['status' => 'cancelled']);

        return PaybillWatch::create([
            'customer_id' => $input['customer_id'] ?? null,
            'phone' => $phone,
            'amount' => $amount,
            'trip_id' => $input['trip_id'] ?? null,
            'order_id' => $input['order_id'] ?? null,
            'driver_trip_sale_id' => $input['driver_trip_sale_id'] ?? null,
            'initiated_by' => $user->id,
            'channel' => $input['channel'] ?? ($input['trip_id'] ?? null ? 'field_trip' : 'warehouse'),
            'status' => 'open',
            'expires_at' => now()->addMinutes(90),
        ]);
    }

    /** If this sale already has a unique unmatched C2B of the same amount, attach it and skip STK. */
    public function attachOpenC2bToTripSale(DriverTripSale $sale): ?MpesaPayment
    {
        $sale->loadMissing('customer');
        $hit = $this->findOpenC2b($sale->customer_id, $sale->customer?->phone, (float) $sale->amount);
        if (! $hit) {
            return null;
        }

        return app(MpesaStkService::class)->assignToSale($hit, 'trip', $sale->id);
    }

    public function attachOpenC2bToOrder(Order $order): ?MpesaPayment
    {
        $order->loadMissing('customer');
        $hit = $this->findOpenC2b($order->customer_id, $order->customer?->phone, (float) $order->total_amount);
        if (! $hit) {
            return null;
        }

        return app(MpesaStkService::class)->assignToSale($hit, 'order', $order->id);
    }

    private function findOpenC2b(?string $customerId, ?string $phone, float $amount): ?MpesaPayment
    {
        $q = MpesaPayment::query()
            ->where('source', 'c2b')
            ->where('status', 'success')
            ->whereNull('order_id')
            ->whereNull('driver_trip_sale_id');
        $last9 = $phone ? self::last9($phone) : '';
        if ($customerId && strlen($last9) >= 9) {
            $q->where(function ($inner) use ($customerId, $last9) {
                $inner->where('customer_id', $customerId)
                    ->orWhere('phone', 'like', '%'.$last9);
            });
        } elseif ($customerId) {
            $q->where('customer_id', $customerId);
        } elseif (strlen($last9) >= 9) {
            $q->where('phone', 'like', '%'.$last9);
        } else {
            return null;
        }

        $hits = $q->get()->filter(fn (MpesaPayment $p) => abs((float) $p->amount - $amount) <= 1.0)->values();

        return $hits->count() === 1 ? $hits->first() : null;
    }

    public function claim(MpesaPayment $payment, $user, ?string $saleType = null, ?string $saleId = null): MpesaPayment
    {
        if (($payment->source ?? 'stk') !== 'c2b' || $payment->status !== 'success') {
            throw new \InvalidArgumentException('Only a confirmed Paybill payment can be claimed.');
        }
        if ($payment->order_id || $payment->driver_trip_sale_id) {
            throw new \InvalidArgumentException('This payment is already linked to a sale.');
        }

        $allowedPhones = $this->driverTodayCustomerPhones($user->id);
        $last9 = self::last9($payment->phone);
        if (! in_array($last9, $allowedPhones, true)) {
            throw new \InvalidArgumentException('You can only claim a Paybill from a customer on your trip today.');
        }

        $customers = $this->customersByPhone($payment->phone)
            ->filter(fn (Customer $c) => in_array(self::last9((string) $c->phone), $allowedPhones, true));

        $trip = DriverTrip::query()
            ->where('driver_id', $user->id)
            ->where(function ($q) {
                $q->where('status', 'in_transit')
                    ->orWhereDate('trip_date', now()->toDateString());
            })
            ->orderByDesc('updated_at')
            ->first();

        $payment->initiated_by = $user->id;
        $payment->trip_id = $trip?->id ?: $payment->trip_id;
        $payment->customer_id = $customers->count() === 1 ? $customers->first()->id : $payment->customer_id;
        $payment->channel = 'field_trip';
        $payment->match_method = 'manual';
        $payment->unmatched = true;
        $payment->save();

        if ($saleType && $saleId) {
            return app(MpesaStkService::class)->assignToSale($payment->fresh(), $saleType === 'order' ? 'order' : 'trip', $saleId);
        }

        $this->matchUnpaidSale($payment->fresh(), $payment->customer_id ? Customer::find($payment->customer_id) : null);

        return $payment->fresh();
    }

    /** @return list<string> last-9 digit phones of customers on this driver's trips today / in transit */
    public function driverTodayCustomerPhones(string $userId): array
    {
        $tripIds = DriverTrip::query()
            ->where('driver_id', $userId)
            ->where(function ($q) {
                $q->where('status', 'in_transit')
                    ->orWhereDate('trip_date', now()->toDateString());
            })
            ->pluck('id');

        $customerIds = DriverTripSale::query()
            ->whereIn('driver_trip_id', $tripIds)
            ->whereNotNull('customer_id')
            ->pluck('customer_id');

        return Customer::whereIn('id', $customerIds)
            ->whereNotNull('phone')
            ->get()
            ->map(fn (Customer $c) => self::last9((string) $c->phone))
            ->filter(fn ($p) => strlen($p) >= 9)
            ->unique()
            ->values()
            ->all();
    }

    public function driverVisibleQuery($user)
    {
        $q = MpesaPayment::query()->where('source', 'c2b');
        if (method_exists($user, 'hasAccessTier') && ($user->hasAccessTier('manager') || $user->hasAccessTier('director'))) {
            return $q;
        }

        $tripIds = DriverTrip::query()->where('driver_id', $user->id)->pluck('id');
        $phones = $this->driverTodayCustomerPhones($user->id);

        return $q->where(function ($inner) use ($user, $tripIds, $phones) {
            $inner->where('initiated_by', $user->id)
                ->orWhereIn('trip_id', $tripIds);
            if ($phones) {
                $inner->orWhere(function ($u) use ($phones) {
                    $u->where('unmatched', true)->where(function ($p) use ($phones) {
                        foreach ($phones as $last9) {
                            $p->orWhere('phone', 'like', '%'.$last9);
                        }
                    });
                });
            }
        });
    }

    private function notify(MpesaPayment $payment, ?string $customerName): void
    {
        $userId = $payment->initiated_by;
        if (! $userId) {
            return;
        }
        $who = $customerName ?: ($payment->payer_name ?: $payment->phone);
        $kes = number_format((float) $payment->amount, 0);
        try {
            Notification::create([
                'user_id' => $userId,
                'channel' => 'in_app',
                'type' => 'info',
                'title' => 'Paybill received',
                'body' => "KES {$kes} from {$who} ({$payment->phone})".($payment->mpesa_receipt ? " · {$payment->mpesa_receipt}" : ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('paybill_notify_failed', ['error' => $e->getMessage()]);
        }
    }
}

<?php

namespace App\Services;

use App\Models\DriverTripSaleItem;
use App\Models\MpesaPayment;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Round 6: the one place that turns a settled payment result (from a
 * real signed webhook, or PaymentGatewayClient's mock) into real state
 * -- the actual sale row, the actual stock/revenue effects. Both
 * PaymentController::webhook() and the mock path in
 * PaymentGatewayClient::initiateStk() call this, so there is exactly
 * one implementation of "what success/failure means", not two that can
 * drift apart.
 *
 * "Never mark the sale paid without a confirmed result" is enforced
 * structurally here, not just by a check: for field_trip, the
 * DriverTripSale is created *by this method*, only when status is
 * 'success' -- there is no code path that creates it any other way
 * while payment_method is the STK flow, so a failed/cancelled/timed-out
 * push can never leave behind a sale, no reversal logic required.
 */
class PaymentSettlementService
{
    /**
     * @param array $payload Shape both the mock caller and the real
     *   webhook handler normalize to before calling this:
     *   ['payment_id','reference','status','result_code','result_desc',
     *    'mpesa_receipt','amount','phone','paid_at']
     */
    public function settle(array $payload): MpesaPayment
    {
        $reference = $payload['reference'] ?? null;
        $gatewayPaymentId = $payload['payment_id'] ?? null;

        return DB::transaction(function () use ($payload, $reference, $gatewayPaymentId) {
            // Correlate by MARA's own reference first -- that's what
            // the gateway's forwarded webhook body carries per the
            // contract (Phase A2); gateway_payment_id is a secondary
            // matcher for a retry/poll that only has that id on hand.
            $payment = ($reference ? MpesaPayment::where('reference', $reference)->lockForUpdate()->first() : null)
                ?? ($gatewayPaymentId ? MpesaPayment::where('gateway_payment_id', $gatewayPaymentId)->lockForUpdate()->first() : null);

            if (!$payment) {
                // Round 6 Phase B5: a payment MARA never initiated --
                // most likely a manual Paybill payment the gateway
                // routed here by BillRefNumber prefix but couldn't tie
                // to a sale. Land it on the Unmatched tab rather than
                // dropping it silently.
                return MpesaPayment::create([
                    'channel' => 'admin',
                    'reference' => $reference ?? ('UNMATCHED-' . substr((string) $gatewayPaymentId, 0, 8)),
                    'gateway_payment_id' => $gatewayPaymentId,
                    'checkout_request_id' => $payload['checkout_request_id'] ?? null,
                    'phone' => $payload['phone'] ?? '',
                    'amount' => $payload['amount'] ?? 0,
                    'status' => $payload['status'] ?? 'success',
                    'result_code' => $payload['result_code'] ?? null,
                    'result_desc' => $payload['result_desc'] ?? null,
                    'mpesa_receipt' => $payload['mpesa_receipt'] ?? null,
                    'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                    'unmatched' => true,
                    'paid_at' => $payload['paid_at'] ?? null,
                ]);
            }

            // Idempotent -- a webhook can be (and per the gateway's own
            // retry-with-backoff design, will sometimes be) delivered
            // more than once.
            if ($payment->isSettled()) {
                Log::info('PaymentSettlementService: ignoring already-settled payment', ['id' => $payment->id, 'status' => $payment->status]);
                return $payment;
            }

            $payment->update([
                'gateway_payment_id' => $payment->gateway_payment_id ?? $gatewayPaymentId,
                'status' => $payload['status'],
                'result_code' => $payload['result_code'] ?? null,
                'result_desc' => $payload['result_desc'] ?? null,
                'mpesa_receipt' => $payload['mpesa_receipt'] ?? null,
                'paid_at' => $payload['paid_at'] ?? null,
            ]);

            if ($payload['status'] === 'success') {
                if ($payment->channel === 'field_trip') {
                    $this->createDeferredTripSale($payment);
                } else {
                    $this->confirmOrderPayment($payment);
                }
            }
            // failed/cancelled/timeout: nothing else to do -- for
            // field_trip no sale was ever created, for Order-based
            // channels payment_method stays unset, so neither ever
            // counted toward revenue (Order::completedSale() scopes on
            // whereNotNull('payment_method')).

            return $payment->fresh();
        });
    }

    /**
     * Mirrors DriverTripController::addSale()'s mpesa-only path (debt
     * isn't reachable via STK, so that branch doesn't apply here) --
     * keep the two in sync if either changes. Re-validates availability
     * at settlement time in case a Director corrected dispatched
     * quantities while this push was in flight; if that now oversells,
     * the confirmed money is never dropped -- it's recorded and flagged
     * as a stock discrepancy instead, same philosophy as
     * DriverTripController::end()'s own oversold-after-correction case.
     */
    private function createDeferredTripSale(MpesaPayment $payment): void
    {
        $snapshot = $payment->pending_sale_snapshot;
        if (!$snapshot) {
            Log::error('PaymentSettlementService: success with no pending_sale_snapshot', ['id' => $payment->id]);
            return;
        }

        $trip = \App\Models\DriverTrip::with('items')->find($snapshot['driver_trip_id']);
        if (!$trip) {
            Log::error('PaymentSettlementService: trip no longer exists for a settled STK sale', ['id' => $payment->id]);
            return;
        }

        $sale = $trip->sales()->create([
            'customer_id' => $snapshot['customer_id'],
            'payment_method' => 'mpesa',
            'amount' => $snapshot['amount'],
            'mpesa_reference' => $payment->mpesa_receipt,
            'physical_receipt_no' => $snapshot['physical_receipt_no'] ?? null,
            'physical_delivery_note_no' => $snapshot['physical_delivery_note_no'] ?? null,
            'photo_url' => $snapshot['photo_url'] ?? null,
            'created_by' => $snapshot['created_by'],
            'updated_by' => $snapshot['created_by'],
        ]);

        $alreadySoldBySku = DriverTripSaleItem::whereHas('sale', fn ($q) => $q->where('driver_trip_id', $trip->id)->whereNull('deleted_at')->where('id', '!=', $sale->id))
            ->selectRaw('sku_id, SUM(qty_bales) as sold')->groupBy('sku_id')->pluck('sold', 'sku_id');

        foreach ($snapshot['items'] as $line) {
            $sale->items()->create([
                'sku_id' => $line['sku_id'],
                'qty_bales' => $line['qty_bales'],
                'unit_price' => $line['unit_price'],
                'line_total' => round($line['qty_bales'] * $line['unit_price'], 2),
            ]);

            $tripItem = $trip->items->firstWhere('sku_id', $line['sku_id']);
            $dispatched = (float) ($tripItem?->qty_carried_bales ?? 0);
            $soldBeforeThis = (float) ($alreadySoldBySku[$line['sku_id']] ?? 0);
            if ($soldBeforeThis + $line['qty_bales'] > $dispatched + 0.001) {
                \App\Models\Discrepancy::create([
                    'driver_trip_id' => $trip->id,
                    'vehicle_id' => $trip->vehicle_id,
                    'driver_id' => $trip->driver_id,
                    'date' => $trip->trip_date,
                    'category' => 'stock',
                    'amount' => round($soldBeforeThis + $line['qty_bales'] - $dispatched, 2),
                    'description' => "M-Pesa STK sale confirmed (receipt {$payment->mpesa_receipt}) for {$line['qty_bales']} bales of {$tripItem?->sku?->name} oversells this trip's dispatched quantity -- dispatched was likely corrected downward after this payment was initiated. Money is confirmed and recorded; review the dispatched-quantity correction.",
                    'status' => 'open',
                    'created_by' => $snapshot['created_by'],
                    'updated_by' => $snapshot['created_by'],
                ]);
                $trip->update(['has_discrepancy' => true]);
            }
        }

        $payment->update(['driver_trip_sale_id' => $sale->id]);
    }

    private function confirmOrderPayment(MpesaPayment $payment): void
    {
        $order = Order::find($payment->order_id);
        if (!$order) {
            Log::error('PaymentSettlementService: order no longer exists for a settled STK payment', ['id' => $payment->id]);
            return;
        }

        $order->update([
            'payment_method' => 'mpesa',
            'payment_reference' => $payment->mpesa_receipt,
        ]);
    }
}

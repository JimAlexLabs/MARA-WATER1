<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Round 6 Phase B1: the only thing in MARA that talks to the shared
 * AfriGig payment gateway (Part A, a separate service MARA does not
 * own). The browser never calls the gateway directly -- see
 * PaymentController, which is the only caller of this class.
 *
 * Mock mode: there is no real AfriGig gateway deployment to point at
 * yet (its repo has no actual Daraja/STK/webhook implementation -- see
 * GATEWAY_CHANGES_FOR_AFRIGIG.md for the hand-off this was built
 * against). `initiateStk()` in mock mode settles the payment
 * immediately by calling straight into PaymentSettlementService --
 * the exact same code path a real signed webhook would hit -- rather
 * than faking a response and skipping it. PAYMENT_MOCK defaults to
 * true whenever PAYMENT_GATEWAY_URL isn't configured, so a fresh
 * environment never accidentally tries to call a gateway that doesn't
 * exist.
 */
class PaymentGatewayClient
{
    public function __construct(private PaymentSettlementService $settlement)
    {
    }

    public function isMock(): bool
    {
        if (!config('services.payment_gateway.url')) {
            return true;
        }
        return (bool) config('services.payment_gateway.mock', true);
    }

    /**
     * POST /api/v1/payments/stk on the real gateway. Returns
     * ['payment_id' => ..., 'status' => 'pending', 'checkout_request_id' => ...].
     */
    public function initiateStk(string $reference, string $phone, float $amount, string $description, string $idempotencyKey, array $metadata = []): array
    {
        if ($this->isMock()) {
            // Test-only convenience so Phase 8's verification checklist
            // (success/cancel/timeout/wrong-PIN) is actually exercisable
            // without a real sandbox: the LAST digit of the phone number
            // picks the outcome. Anything else succeeds.
            $outcome = match (substr($phone, -1)) {
                '1' => ['status' => 'cancelled', 'result_code' => '1032', 'result_desc' => 'Request cancelled by user'],
                '2' => ['status' => 'timeout', 'result_code' => '1037', 'result_desc' => 'No response from user'],
                '3' => ['status' => 'failed', 'result_code' => '2001', 'result_desc' => 'Wrong PIN entered'],
                default => ['status' => 'success', 'result_code' => '0', 'result_desc' => 'The service request is processed successfully.'],
            };

            $checkoutRequestId = 'ws_CO_MOCK_' . Str::upper(Str::random(10));
            $gatewayPaymentId = (string) Str::uuid();

            Log::info('PaymentGatewayClient (mock): STK initiated', [
                'reference' => $reference, 'amount' => $amount, 'outcome' => $outcome['status'],
            ]);

            // Settle synchronously -- same path a real webhook takes,
            // just without the network hop or the HMAC check (there's
            // nothing to verify a signature against in mock mode).
            $this->settlement->settle([
                'payment_id' => $gatewayPaymentId,
                'reference' => $reference,
                'status' => $outcome['status'],
                'result_code' => $outcome['result_code'],
                'result_desc' => $outcome['result_desc'],
                'mpesa_receipt' => $outcome['status'] === 'success' ? ('MOCK' . Str::upper(Str::random(8))) : null,
                'amount' => $amount,
                'phone' => $phone,
                'paid_at' => $outcome['status'] === 'success' ? now()->toIso8601String() : null,
            ]);

            return [
                'payment_id' => $gatewayPaymentId,
                'status' => 'pending', // the caller's local row starts pending; settle() above updates it to the real outcome in the same request.
                'checkout_request_id' => $checkoutRequestId,
            ];
        }

        $response = Http::withToken(config('services.payment_gateway.api_key'))
            ->timeout(15)
            ->post(rtrim(config('services.payment_gateway.url'), '/') . '/api/v1/payments/stk', [
                'reference' => $reference,
                'phone' => $phone,
                'amount' => $amount,
                'description' => $description,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);

        if (!$response->successful()) {
            Log::error('PaymentGatewayClient: STK initiate failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Payment gateway did not accept the STK request: ' . $response->status());
        }

        return $response->json();
    }

    /** GET /api/v1/payments/:payment_id on the real gateway -- the poll fallback for a webhook that never arrives. */
    public function getPaymentStatus(string $gatewayPaymentId): ?array
    {
        if ($this->isMock()) {
            // Mock payments settle synchronously inside initiateStk();
            // there's nothing left to poll for.
            return null;
        }

        $response = Http::withToken(config('services.payment_gateway.api_key'))
            ->timeout(10)
            ->get(rtrim(config('services.payment_gateway.url'), '/') . "/api/v1/payments/{$gatewayPaymentId}");

        return $response->successful() ? $response->json() : null;
    }
}

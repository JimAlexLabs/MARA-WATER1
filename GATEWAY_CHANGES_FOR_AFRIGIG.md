# Gateway changes needed for AfriGig (Part A of Round 6)

MARA Water's side ("Part B") is built and live in mock mode. This file is the hand-off requested by `MARA_WATER_ROUND6_MPESA_STK_PUSH_VIA_AFRIGIG_GATEWAY.md` Phase 0: exactly what the gateway itself (Part A, AfriGig's repo) still needs to implement before MARA can switch off `PAYMENT_MOCK`.

## What was checked on the AfriGig side

I don't have write access to an AfriGig repo as a working directory, so this is read-only reconnaissance of what's locally available on this machine, not a live integration:

- **The real AfriGig backend** (`afrigig-backend`, Node.js + Express + PostgreSQL — Africa's freelancing-marketplace platform: jobs, escrow, wallets). Its only M-Pesa code is in `payments.controller.js`'s `depositEscrow()`:
  ```js
  // Simulate STK push (in production: call Daraja API)
  await new Promise(r => setTimeout(r, 300));
  ```
  No Daraja client, no callback/webhook handler, no `checkout_request_id`/`merchant_request_id` columns, no multi-tenant concept (`payment_clients`, `app_id`, API keys) anywhere in its schema or code.
- **The SQL schema dump** (`afrigig_schema.sql`) has a generic `payments` table (`job_id`, `amount`, `status: pending|escrow|released|refunded|failed`, `reference`) built for the escrow workflow, not a payment-gateway ledger — no STK/webhook-shaped columns there either.
- Several `afrigig-connect-*` directories are frontend-only exports (Lovable-style React apps) — nothing backend-relevant.

**Conclusion: there is currently no real Daraja/STK/webhook implementation to reuse anywhere accessible.** Part B below was built fully against the API contract from the Round 6 script's own Phase A2, with a mock gateway standing in (see `PAYMENT_MOCK` in MARA's `.env.example`) — not against any existing AfriGig code, because none exists yet.

## What Part A actually needs to implement

This is the real contract MARA's `PaymentGatewayClient` (`backend/mara-water-api/app/Services/PaymentGatewayClient.php`) and webhook receiver (`PaymentController::webhook`) are built against today. Matching it exactly means MARA can flip `PAYMENT_MOCK=false` with no MARA-side changes.

### A1 — Tenants and API keys
- `payment_clients` table: `id`, `app_id` (unique — register `mara_water`), `name`, `api_key_hash`, `webhook_url`, `webhook_secret`, `reference_prefix` (`MARA-`), `active`, `allowed_ips` (optional).
- Issue MARA an API key + webhook secret. MARA's side is ready for them: `PAYMENT_GATEWAY_URL`, `PAYMENT_GATEWAY_API_KEY`, `PAYMENT_WEBHOOK_SECRET` in `backend/mara-water-api/.env` (server-side only, never shipped to the frontend).

### A2 — API contract (what MARA calls)

**`POST /api/v1/payments/stk`** — `Authorization: Bearer <MARA_API_KEY>`, JSON body:
```json
{ "reference": "MARA-ABC1234", "phone": "2547XXXXXXXX", "amount": 4800, "description": "MARA Water field sale MARA-ABC1234", "idempotency_key": "<uuid>", "metadata": {} }
```
Response: `{ "payment_id": "<gateway's own id>", "status": "pending", "checkout_request_id": "ws_CO_..." }`

MARA's actual request-building code (for exact field names/shapes):
```php
// app/Services/PaymentGatewayClient.php::initiateStk()
Http::withToken(...)->post($url . '/api/v1/payments/stk', [
    'reference' => $reference, 'phone' => $phone, 'amount' => $amount,
    'description' => $description, 'idempotency_key' => $idempotencyKey, 'metadata' => $metadata,
]);
```
- Reject a repeat `idempotency_key` by returning the original payment, never re-pushing.
- `reference` is already MARA's own short code (`MARA-` + 7 chars, ≤12 chars total) — use it directly as Safaricom's `AccountReference`.
- Phone is already normalized to `2547XXXXXXXX` / `2541XXXXXXXX` before this call (MARA validates with `^254(7|1)\d{8}$`).

**`GET /api/v1/payments/:payment_id`** — current status, for `PaymentController::status()`'s poll-if-webhook-missing fallback (`PaymentGatewayClient::getPaymentStatus()`).

**Webhook to MARA** — `POST {webhook_url}` (MARA's endpoint: `POST /api/v1/payments/webhook`, publicly reachable, no auth header expected):
- Header: `X-Signature: HMAC-SHA256(webhook_secret, raw_request_body)` hex digest.
- Body MARA expects (`PaymentController::webhook()`'s validation):
  ```json
  {
    "payment_id": "<gateway's id>", "reference": "MARA-ABC1234",
    "status": "success | failed | cancelled | timeout",
    "result_code": "0", "result_desc": "...",
    "mpesa_receipt": "QGH7XXXXX", "amount": 4800, "phone": "2547XXXXXXXX",
    "paid_at": "2026-10-08T10:00:00Z"
  }
  ```
- MARA verifies the signature with `hash_equals()` (constant-time) and returns 401 on mismatch, 503 if it has no `PAYMENT_WEBHOOK_SECRET` configured. Retry with backoff on anything but a 2xx — MARA's handler is idempotent (a repeat delivery after settlement is a safe no-op), so retries are safe to send liberally.
- `reference` is the primary correlation key MARA uses to find the payment it's settling (falls back to `payment_id` only if `reference` is absent) — always include it.

### A3 — Callback routing (Safaricom-facing, gateway-internal)
- STK callback handler looks up `checkout_request_id` → `(app_id, reference)` → forwards to that app's webhook. Always ACK Safaricom `200 {"ResultCode":0,"ResultDesc":"Accepted"}` immediately; forward asynchronously.
- C2B (manual Paybill) validation/confirmation routes by `BillRefNumber` prefix (`MARA-` → `mara_water`). A `BillRefNumber` with the right prefix but no reference MARA recognizes should still be forwarded to MARA's webhook with whatever `reference` was typed — MARA's `PaymentSettlementService` already handles "a webhook for a reference I don't know about" by filing it on the Unmatched tab for manual admin review rather than dropping it, so the gateway doesn't need its own separate unmatched-routing logic for MARA specifically — just forward by prefix and let MARA sort out the rest.
- A reconciliation job: anything still `pending` ~2 minutes after the STK push should trigger a Safaricom STK Push Query call and settle from that (callbacks do sometimes not arrive) — same shape as the normal webhook forward.

## What MARA is NOT asking the gateway to do

- No per-sale business logic — MARA computes its own amounts server-side and never trusts a client-supplied figure; the gateway only needs to move the number MARA sends it.
- No stock/inventory/customer data — MARA never sends more than `reference`/`phone`/`amount`/`description`.

## Verification once A is live

1. Set real `PAYMENT_GATEWAY_URL` / `PAYMENT_GATEWAY_API_KEY` / `PAYMENT_WEBHOOK_SECRET`, `PAYMENT_MOCK=false`.
2. Run Round 6's own Phase 8 verification list (sandbox STK, tamper test on the webhook signature, replay test, double-tap test, manual-Paybill routing, cross-tenant isolation with a real AfriGig payment, webhook-killed reconciliation poll).

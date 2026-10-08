<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 6: M-Pesa STK Push via the shared AfriGig payment gateway.
 *
 * MARA is a *client* of a multi-tenant gateway (AfriGig hosts the
 * Daraja app, Paybill, and the one callback URL Safaricom allows per
 * shortcode) -- this table is MARA's own ledger of what it asked the
 * gateway to do and what the gateway told it happened, not a mirror of
 * the gateway's internal state. Every write path either comes from a
 * MARA-initiated STK request or a signed webhook from the gateway; see
 * PaymentController for both.
 *
 * sale_id is deliberately two nullable FKs rather than one polymorphic
 * column -- a field-trip sale (DriverTripSale) and a counter/warehouse
 * sale (Order) are MARA's two real sale entities, and the rest of this
 * codebase already prefers explicit nullable FKs over polymorphism
 * (DriverTripSale.debt_id, etc.) -- exactly one of the two is set,
 * matching `channel` (field_trip -> driver_trip_sale_id, everything
 * else -> order_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->enum('channel', ['field_trip', 'warehouse', 'refill', 'admin']);
            $table->uuid('driver_trip_sale_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->uuid('trip_id')->nullable();
            $table->uuid('location_id')->nullable();
            // Round 6: "Never mark the sale paid without a confirmed
            // result" -- for field_trip, the DriverTripSale itself isn't
            // created until the STK push actually succeeds (so a failed/
            // cancelled push never touches stock, the sold tally, or
            // revenue -- no reversal logic needed because nothing was
            // ever provisionally counted). This column holds everything
            // addSale() needs to create that sale, captured at the
            // moment the driver hits "Send payment request".
            $table->json('pending_sale_snapshot')->nullable();

            // MARA's own human reference, e.g. "MARA-S10482" -- also set
            // as Safaricom's AccountReference on the STK push, so it
            // shows on the customer's M-Pesa statement too (see Phase 0's
            // "safety net" layer).
            $table->string('reference', 20);

            // The gateway's own identifiers for this payment.
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('checkout_request_id')->nullable()->index();
            $table->string('merchant_request_id')->nullable();

            $table->string('phone', 15);
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'success', 'failed', 'cancelled', 'timeout'])->default('pending')->index();
            $table->string('result_code')->nullable();
            $table->string('result_desc')->nullable();
            // MySQL's UNIQUE allows any number of NULL rows, which is
            // exactly "unique where not null" -- no partial index needed.
            $table->string('mpesa_receipt')->nullable()->unique();

            $table->uuid('initiated_by')->nullable();
            // Protects against a double-tapped "Send payment request"
            // ever reaching the gateway twice -- generated once per STK
            // attempt, unique, checked before calling out.
            $table->string('idempotency_key')->unique();

            // Round 6 Phase B5: an unmatched manual-Paybill payment the
            // gateway routed to mara_water by BillRefNumber prefix but
            // that doesn't match any sale MARA knows about -- surfaced on
            // the Unmatched tab until a Manager/Director assigns it.
            $table->boolean('unmatched')->default(false)->index();
            $table->uuid('assigned_sale_id')->nullable();
            $table->string('assigned_sale_type', 20)->nullable(); // 'driver_trip_sale' | 'order'
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('driver_trip_sale_id')->references('id')->on('driver_trip_sales')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('trip_id')->references('id')->on('driver_trips')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('initiated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_payments');
    }
};

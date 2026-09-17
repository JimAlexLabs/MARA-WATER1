<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 7 (Driver Trip Log rebuild). Zero real driver trips
 * exist live yet (confirmed before writing this), so this can cleanly
 * replace rather than layer on top of:
 *
 * - oil_liters dropped entirely, per the spec's explicit instruction.
 * - cash_collected/mpesa_collected/mpesa_reference and Phase 6's
 *   debt_customer_id/debt_signatory/debt_amount/debt_expected_repayment_date/
 *   debt_id dropped from driver_trips -- all superseded by the new
 *   driver_trip_sales table below, which supports multiple sales per
 *   trip (one per customer/stop), each with its own payment method and
 *   buyer, instead of one lump total. This is the "subsume Phase 6's
 *   single-debt-per-trip field into the fuller per-sale design"
 *   mentioned when Phase 6 shipped.
 * - driver_trip_items gets a real qty_sold column -- previously "sold"
 *   was only ever implied (carried minus returned). The spec explicitly
 *   asks for dispatched/returned/sold as three independent numbers per
 *   product/size, which lets the app catch a real discrepancy (e.g. a
 *   dropped/broken bottle) between what the math implies and what the
 *   driver actually reports selling, instead of assuming they always
 *   match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_trip_items', function (Blueprint $table) {
            $table->unsignedInteger('qty_sold')->default(0)->after('qty_returned');
        });

        Schema::table('driver_trips', function (Blueprint $table) {
            $table->dropForeign(['debt_customer_id']);
            $table->dropForeign(['debt_id']);
            $table->dropColumn([
                'oil_liters',
                'cash_collected', 'mpesa_collected', 'mpesa_reference',
                'debt_customer_id', 'debt_signatory', 'debt_amount', 'debt_expected_repayment_date', 'debt_id',
            ]);
        });

        Schema::create('driver_trip_sales', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('driver_trip_id', 36);
            $table->char('customer_id', 36);
            $table->enum('payment_method', ['cash', 'mpesa', 'debt']);
            $table->decimal('amount', 12, 2);
            $table->string('mpesa_reference', 100)->nullable();
            // Debt-specific -- same discipline as Phase 6's OrderController
            // credit sales: a named signatory and a repayment date capped
            // at 7 days, enforced in the controller, not just here.
            $table->string('debt_signatory', 150)->nullable();
            $table->date('debt_expected_repayment_date')->nullable();
            $table->char('debt_id', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->char('created_by', 36)->nullable();
            $table->char('updated_by', 36)->nullable();

            $table->foreign('driver_trip_id')->references('id')->on('driver_trips')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('debt_id')->references('id')->on('debts')->nullOnDelete();
            $table->index('driver_trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_trip_sales');

        Schema::table('driver_trips', function (Blueprint $table) {
            $table->decimal('oil_liters', 8, 2)->nullable();
            $table->decimal('cash_collected', 12, 2)->default(0);
            $table->decimal('mpesa_collected', 12, 2)->default(0);
            $table->string('mpesa_reference', 100)->nullable();
            $table->char('debt_customer_id', 36)->nullable();
            $table->string('debt_signatory', 150)->nullable();
            $table->decimal('debt_amount', 12, 2)->default(0);
            $table->date('debt_expected_repayment_date')->nullable();
            $table->char('debt_id', 36)->nullable();
        });

        Schema::table('driver_trip_items', function (Blueprint $table) {
            $table->dropColumn('qty_sold');
        });
    }
};

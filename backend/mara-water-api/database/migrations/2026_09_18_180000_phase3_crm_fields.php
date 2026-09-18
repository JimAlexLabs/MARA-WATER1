<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 Phase 3: standalone Customer/Sales module fields.
 *
 * - customers.type widened (not replaced) with 'walk_in' -- the spec's
 *   four categories are Retail/Distributor/Institution/Walk-in;
 *   retail/wholesale/corporate/hotel_restaurant (Round 2's real, already-
 *   seeded categories) keep their existing rows valid, walk_in is new.
 * - driver_trip_sales.payment_method widened with 'pay_direct' (QR or a
 *   manual reference note, alongside cash/mpesa/debt) -- reuses the
 *   existing mpesa_reference column for the reference code/QR
 *   transaction id rather than adding a duplicate column (see
 *   DriverTripSale model docblock).
 * - driver_trip_sales gets physical_receipt_no/physical_delivery_note_no
 *   -- optional, driver keeps writing the paper receipt/delivery note as
 *   today and just also types the number in here, so paper <-> app stay
 *   two-way lookupable without changing how drivers work.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customers MODIFY type ENUM('retail','wholesale','corporate','hotel_restaurant','walk_in') NOT NULL DEFAULT 'retail'");
        DB::statement("ALTER TABLE driver_trip_sales MODIFY payment_method ENUM('cash','mpesa','debt','pay_direct') NOT NULL");

        Schema::table('driver_trip_sales', function (Blueprint $table) {
            $table->string('physical_receipt_no', 50)->nullable()->after('mpesa_reference');
            $table->string('physical_delivery_note_no', 50)->nullable()->after('physical_receipt_no');
        });
    }

    public function down(): void
    {
        Schema::table('driver_trip_sales', function (Blueprint $table) {
            $table->dropColumn(['physical_receipt_no', 'physical_delivery_note_no']);
        });
        DB::statement("ALTER TABLE driver_trip_sales MODIFY payment_method ENUM('cash','mpesa','debt') NOT NULL");
        DB::statement("ALTER TABLE customers MODIFY type ENUM('retail','wholesale','corporate','hotel_restaurant') NOT NULL DEFAULT 'retail'");
    }
};

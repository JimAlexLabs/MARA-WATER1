<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 5A Phase 3: "Optional photo attachment on a sale or field visit
 * entry -- for sales/marketing reporting purposes (proof of delivery, a
 * photo of the customer's shop/stock, a scanned paper receipt). Store it
 * against the relevant Sale ... record; keep it optional."
 *
 * Reuses the existing FileUploadController (already used by Round 3
 * Phase 5's Issue photo attachment) -- this just gives DriverTripSale
 * somewhere to keep the URL it returns. Nullable, no default: existing
 * rows simply have no photo, exactly like an issue with no attachment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_trip_sales', function (Blueprint $table) {
            $table->string('photo_url', 500)->nullable()->after('physical_delivery_note_no');
        });
    }

    public function down(): void
    {
        Schema::table('driver_trip_sales', function (Blueprint $table) {
            $table->dropColumn('photo_url');
        });
    }
};

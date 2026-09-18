<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 Phase 2/3: per-brand/size line items on a driver-trip sale, so
 * stage 4's "running tally, by brand/size" (Phase 2) has real data to
 * pull from instead of only a lump amount. Qty is in bales (a separate,
 * parallel unit from the bottle-level qty_carried/qty_sold that still
 * drives stock deduction and the trip's revenue reconciliation) --
 * unit_price/line_total here are the driver's own entered sale price,
 * not derived from the bottle-level SKU catalog price, since there's no
 * bale<->bottle conversion. driver_trip_sales.amount stays the
 * authoritative total for cash/M-Pesa/debt reconciliation; these items
 * are an additional, optional breakdown, not a replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_trip_sale_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('driver_trip_sale_id', 36);
            $table->char('sku_id', 36);
            $table->decimal('qty_bales', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->foreign('driver_trip_sale_id')->references('id')->on('driver_trip_sales')->cascadeOnDelete();
            $table->foreign('sku_id')->references('id')->on('skus');
            $table->index('driver_trip_sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_trip_sale_items');
    }
};

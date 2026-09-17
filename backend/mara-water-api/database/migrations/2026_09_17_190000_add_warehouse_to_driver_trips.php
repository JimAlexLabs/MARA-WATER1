<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 8 (stock auto-deduction). A driver trip needs a
 * warehouse to draw its loaded stock from and return unsold stock to --
 * exactly the same "outlet" concept Log Sale already requires -- so the
 * trip's stock movements can post to the real ledger (stock_moves/
 * stock_items) the moment it's logged, instead of a separate manual
 * adjustment afterward.
 *
 * NOT NULL like driver_id/vehicle_id (a trip without a source warehouse
 * can't be stock-accounted for) -- safe because zero real driver trips
 * exist yet (verified before writing this, same as Phase 7's migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_trips', function (Blueprint $table) {
            $table->char('warehouse_id', 36)->after('route_id');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
        });
    }

    public function down(): void
    {
        Schema::table('driver_trips', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};

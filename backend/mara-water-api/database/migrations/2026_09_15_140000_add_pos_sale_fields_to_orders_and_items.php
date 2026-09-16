<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 (Sales Management -- replace manual entry). The order/order_items
 * tables already modeled a purchase-order-style draft->confirmed->dispatched
 * pipeline, but the "Log a Sale" flow the spec asks for is a single-step
 * point-of-sale transaction: which outlet/branch it was logged at, how it
 * was paid for, and (per line) how much of what was dispatched came back.
 *
 * price_lists / price_list_items already exist as real tables (seeded with
 * Standard/Wholesale/Corporate lists) -- nothing to add there, just models.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'warehouse_id')) {
                $table->uuid('warehouse_id')->nullable()->after('route_id');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 20)->nullable()->after('total_amount');
            }
            if (!Schema::hasColumn('orders', 'payment_reference')) {
                $table->string('payment_reference', 255)->nullable()->after('payment_method');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'qty_returned')) {
                $table->integer('qty_returned')->default(0)->after('qty');
            }
            if (!Schema::hasColumn('order_items', 'unit_price_overridden')) {
                $table->boolean('unit_price_overridden')->default(false)->after('unit_price');
            }
            if (!Schema::hasColumn('order_items', 'override_reason')) {
                $table->string('override_reason', 255)->nullable()->after('unit_price_overridden');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['qty_returned', 'unit_price_overridden', 'override_reason']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['warehouse_id', 'payment_method', 'payment_reference']);
        });
    }
};

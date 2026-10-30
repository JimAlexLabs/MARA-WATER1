<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finished_goods_backorders')) {
            return;
        }

        Schema::create('finished_goods_backorders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->index();
            $table->uuid('order_item_id')->nullable()->index();
            $table->uuid('sku_id')->index();
            $table->uuid('warehouse_id')->nullable()->index();
            $table->decimal('qty_reserved', 12, 2)->default(0);
            $table->decimal('qty_fulfilled', 12, 2)->default(0);
            $table->string('status', 20)->default('open')->index(); // open | fulfilled | cancelled
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_goods_backorders');
    }
};

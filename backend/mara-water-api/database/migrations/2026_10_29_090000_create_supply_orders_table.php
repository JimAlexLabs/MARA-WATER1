<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supply (purchase) orders placed with Intripet / NEOPACK / bailing-paper
 * suppliers. Open orders count as "on the way" in the Operations reorder
 * advisor, and their cost is treated as cash out in the trip cash trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 30); // bottle_trip|labels|bailing|seals|other
            $table->string('supplier', 200);
            $table->string('reference_no', 100)->nullable();
            $table->date('order_date');
            $table->unsignedSmallInteger('lead_business_days')->default(7);
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->default('placed'); // placed|in_transit|received|cancelled
            $table->date('received_date')->nullable();
            $table->json('lines'); // [{item_code,label,qty,unit_cost_ex_vat}]
            $table->boolean('vat_applies')->default(true);
            $table->decimal('goods_cost', 14, 2)->default(0);   // ex VAT
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('transport_cost', 14, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (Fleet). Replaces the paper driver worksheet: date, driver,
 * vehicle, route, mileage start/end, fuel, oil, authorizing officer,
 * time out/in, stock carried vs. returned per SKU, and cash/M-Pesa
 * collected -- with an automatic carried-minus-returned-vs-collected
 * reconciliation instead of a manual one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_trips', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->date('trip_date');
            $table->char('driver_id', 36);
            $table->char('vehicle_id', 36);
            $table->char('route_id', 36)->nullable();
            $table->unsignedInteger('mileage_start')->nullable();
            $table->unsignedInteger('mileage_end')->nullable();
            $table->decimal('fuel_liters', 8, 2)->nullable();
            $table->decimal('fuel_cost', 12, 2)->nullable();
            $table->decimal('oil_liters', 8, 2)->nullable();
            $table->char('authorizing_officer_id', 36)->nullable();
            $table->time('time_out')->nullable();
            $table->time('time_in')->nullable();
            $table->decimal('cash_collected', 12, 2)->default(0);
            $table->decimal('mpesa_collected', 12, 2)->default(0);
            $table->string('mpesa_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->char('updated_by', 36)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('driver_id')->references('id')->on('users');
            $table->foreign('vehicle_id')->references('id')->on('vehicles');
            $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
            $table->foreign('authorizing_officer_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['vehicle_id', 'trip_date']);
            $table->index('trip_date');
        });

        Schema::create('driver_trip_items', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('driver_trip_id', 36);
            $table->char('sku_id', 36);
            $table->unsignedInteger('qty_carried')->default(0);
            $table->unsignedInteger('qty_returned')->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('driver_trip_id')->references('id')->on('driver_trips')->cascadeOnDelete();
            $table->foreign('sku_id')->references('id')->on('skus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_trip_items');
        Schema::dropIfExists('driver_trips');
    }
};

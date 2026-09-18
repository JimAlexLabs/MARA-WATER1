<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Round 3 Phase 9: a `locations` reference table (KDN, KDQ, Warehouse --
 * confirmed as the two retail/branch codes plus the warehouse itself) so
 * every module that currently only knows "Warehouse" (trip origin,
 * inventory) can also tag records by branch, matching how the real
 * sales summaries split everything three ways for the Daily Sales &
 * Debt export.
 *
 * location_id is deliberately separate from warehouse_id, not a
 * replacement for it: warehouse_id still says which depot the physical
 * stock moved from/to; location_id says which branch/outlet a sale is
 * attributed to for reporting. Nullable and defaulted to nothing here --
 * existing orders/trips predate this concept, and forcing a value on
 * them would be guessing at real business data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->timestamps();
        });

        $now = now();
        DB::table('locations')->insert([
            ['id' => (string) Str::uuid(), 'code' => 'KDN', 'name' => 'KDN', 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'code' => 'KDQ', 'name' => 'KDQ', 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'code' => 'WAREHOUSE', 'name' => 'Warehouse', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('warehouse_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });

        Schema::table('driver_trips', function (Blueprint $table) {
            $table->uuid('location_id')->nullable()->after('warehouse_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_trips', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        Schema::dropIfExists('locations');
    }
};

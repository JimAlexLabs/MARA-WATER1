<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 Phase 2: rebuild the trip log as a staged, lockable workflow
 * (Pre-departure -> Dispatched -> In Transit/locked -> Sales during trip
 * -> Return & close). Zero real driver trips exist live yet (confirmed
 * before writing this, same as Round 2 Phase 7/8's driver_trips
 * migrations), so this can cleanly replace rather than layer on top of:
 *
 * - route_id (FK to the fixed 4-option `routes` table) dropped in favor
 *   of a free-text `route` column, per the spec's explicit "remove the
 *   Route dropdown and its fixed options entirely". The `routes` table
 *   itself is untouched -- it's still used by Customers/Orders for
 *   delivery-route grouping, an unrelated concept this phase doesn't
 *   touch.
 * - `status` drives the stage machine: pending_departure -> in_transit
 *   -> completed. time_out/time_in are repurposed from manually-typed
 *   fields into auto-captured stage-transition timestamps ("Departure
 *   time"/"Return time" in the spec) -- set by the controller when
 *   Start Trip / End Trip fire, no longer client-editable.
 * - has_discrepancy: true when stage-5 reconciliation doesn't match the
 *   stage-4 sales tally (surfaced as a dashboard alert per Phase 6).
 * - locked_at/unlocked_reason support the Director-only unlock action;
 *   full unlock history (who/when/reason/snapshot) lives in the new
 *   driver_trip_unlocks table below, not as columns here.
 *
 * driver_trip_items gets qty_carried_bales/qty_returned_bales -- bales
 * are a separate, parallel unit per the round's design decision (no
 * auto-conversion to the existing bottle-level qty_carried/qty_sold,
 * which keeps driving stock deduction and revenue exactly as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_trips', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
            $table->dropColumn('route_id');
            $table->string('route')->nullable()->after('vehicle_id');

            $table->enum('status', ['pending_departure', 'in_transit', 'completed'])
                ->default('pending_departure')->after('warehouse_id');
            $table->boolean('has_discrepancy')->default(false)->after('status');
            $table->timestamp('locked_at')->nullable()->after('time_in');
        });

        Schema::create('driver_trip_unlocks', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('driver_trip_id', 36);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->text('reason');
            $table->json('snapshot');
            $table->char('unlocked_by', 36);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('driver_trip_id')->references('id')->on('driver_trips')->cascadeOnDelete();
            $table->foreign('unlocked_by')->references('id')->on('users');
        });

        Schema::table('driver_trip_items', function (Blueprint $table) {
            $table->unsignedInteger('qty_carried_bales')->default(0)->after('qty_carried');
            $table->unsignedInteger('qty_returned_bales')->default(0)->after('qty_returned');
        });
    }

    public function down(): void
    {
        Schema::table('driver_trip_items', function (Blueprint $table) {
            $table->dropColumn(['qty_carried_bales', 'qty_returned_bales']);
        });

        Schema::dropIfExists('driver_trip_unlocks');

        Schema::table('driver_trips', function (Blueprint $table) {
            $table->dropColumn(['status', 'has_discrepancy', 'locked_at']);
            $table->dropColumn('route');
            $table->char('route_id', 36)->nullable()->after('vehicle_id');
            $table->foreign('route_id')->references('id')->on('routes')->nullOnDelete();
        });
    }
};

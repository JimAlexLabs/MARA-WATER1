<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (Fleet). VehicleController::store()/update()/statistics() all
 * reference vehicles.fuel_type, vehicles.notes, vehicles.status, and
 * vehicles.driver_id -- none of which exist. status is dropped in favour
 * of the real `active` boolean (already there, already what the frontend
 * checks); driver_id is dropped in favour of the real driver_assignments
 * table (time-bounded, supports reassignment history -- see
 * VehicleController::assignDriver/unassignDriver). fuel_type and notes
 * are genuinely missing concepts, added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'fuel_type')) {
                $table->string('fuel_type', 50)->nullable()->after('capacity');
            }
            if (!Schema::hasColumn('vehicles', 'notes')) {
                $table->text('notes')->nullable()->after('speed_gov_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['fuel_type', 'notes']);
        });
    }
};

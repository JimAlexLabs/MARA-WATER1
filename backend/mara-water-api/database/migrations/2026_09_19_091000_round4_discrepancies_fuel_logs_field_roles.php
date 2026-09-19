<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Round 4 schema:
 *  - discrepancies: Phase 6's dedicated tracking table (date, driver,
 *    vehicle, amount/qty off, category, status, resolution note) --
 *    driver_trips.has_discrepancy stays as the fast filter column it
 *    already was, this is the actual log entries behind it, general
 *    enough to also hold Phase 7's mileage-outlier flags (not every
 *    category is trip-scoped forever, so driver_trip_id is nullable).
 *  - fuel_logs already exists (vehicle_id/date/liters/cost/odometer/
 *    receipt_photo_id) -- it's what DriverTripController::end() has been
 *    writing to from the trip form all along. Phase 8 stops writing to
 *    it from End Trip and gives it its own Manager/Director-only
 *    controller/page instead; no schema change needed here.
 *  - driver_trips.reconciliation_confirmed_at/_by: Phase 5's paper-sales
 *    attestation, timestamped so a later discrepancy has a record that
 *    reconciliation was attested to, not skipped.
 *  - Two new roles (SALES, FIELDWORK) at the driver access tier -- Phase
 *    9's shared Driver/Sales/Field-Work dashboard. Kept at tier=driver
 *    rather than inventing a new access tier: same dashboard, same
 *    permission scope as Driver, just a different job title for HR/
 *    attendance purposes (matches how the existing office roles like QA/
 *    RIC/SMM already collapse onto 'manager' -- see Role::TIERS docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discrepancies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('driver_trip_id')->nullable();
            $table->uuid('vehicle_id')->nullable();
            $table->uuid('driver_id')->nullable();
            $table->date('date');
            $table->enum('category', ['cash', 'stock', 'mileage']);
            $table->decimal('amount', 14, 2)->nullable(); // KES for cash, bales for stock, km for mileage
            $table->text('description');
            $table->enum('status', ['open', 'reviewed', 'resolved'])->default('open');
            $table->text('resolution_note')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('driver_trip_id')->references('id')->on('driver_trips')->nullOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->foreign('driver_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['category', 'status']);
            $table->index('date');
        });

        Schema::table('driver_trips', function (Blueprint $table) {
            $table->timestamp('reconciliation_confirmed_at')->nullable()->after('locked_at');
            $table->uuid('reconciliation_confirmed_by')->nullable()->after('reconciliation_confirmed_at');
            $table->foreign('reconciliation_confirmed_by')->references('id')->on('users')->nullOnDelete();
        });

        $now = now();
        DB::table('roles')->insertOrIgnore([
            ['id' => (string) Str::uuid(), 'code' => 'SALES', 'name' => 'Sales / Field Sales', 'access_tier' => 'driver', 'description' => 'Round 4: the Salesperson riding with a Driver on field trips -- shares the Driver dashboard, own check-in/checkout.', 'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => (string) Str::uuid(), 'code' => 'FIELDWORK', 'name' => 'Field Work / Marketing', 'access_tier' => 'driver', 'description' => 'Round 4: joins the driver/sales team for market/field work on some trips -- same dashboard, same check-in/checkout.', 'is_system' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('driver_trips', function (Blueprint $table) {
            $table->dropForeign(['reconciliation_confirmed_by']);
            $table->dropColumn(['reconciliation_confirmed_at', 'reconciliation_confirmed_by']);
        });
        Schema::dropIfExists('discrepancies');
        DB::table('roles')->whereIn('code', ['SALES', 'FIELDWORK'])->delete();
    }
};

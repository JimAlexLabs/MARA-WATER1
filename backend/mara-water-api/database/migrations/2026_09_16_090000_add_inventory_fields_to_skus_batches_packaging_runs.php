<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 (Inventory Management). skus had no brand distinction (Premium vs
 * Platinum vs Grace vs Refill all matter for the production/refills
 * reporting the spec asks for) and no reorder threshold (low-stock alerts
 * everywhere in the app use one hardcoded "10" for every item regardless
 * of what it actually is). materials already had min_level as its
 * reorder threshold -- this brings skus to parity.
 *
 * batches.actual_qty: PackagingRunController::updateBatchStatus() has
 * been writing to this column since whenever that controller was built,
 * but the column never existed and the field was never in Batch's
 * $fillable -- a pre-existing, silently-dropped write. Adding the column
 * (and, in the model, the fillable entry) makes it real.
 *
 * packaging_runs.warehouse_id: which outlet/warehouse a production run's
 * finished goods land in -- needed so completing a run can post a real
 * stock-in movement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            if (!Schema::hasColumn('skus', 'brand')) {
                $table->string('brand', 50)->nullable()->after('name');
            }
            if (!Schema::hasColumn('skus', 'reorder_threshold')) {
                $table->integer('reorder_threshold')->nullable()->after('active');
            }
        });

        Schema::table('batches', function (Blueprint $table) {
            if (!Schema::hasColumn('batches', 'actual_qty')) {
                $table->integer('actual_qty')->nullable()->after('planned_qty');
            }
        });

        Schema::table('packaging_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('packaging_runs', 'warehouse_id')) {
                $table->uuid('warehouse_id')->nullable()->after('sku_id');
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('packaging_runs', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('actual_qty');
        });

        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn(['brand', 'reorder_threshold']);
        });
    }
};

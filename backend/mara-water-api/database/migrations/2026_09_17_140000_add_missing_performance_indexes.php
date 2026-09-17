<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2, Phase 0 (diagnose slowness before optimizing blindly). Audited
 * every frequently-filtered column against the live indexes and found
 * three real gaps -- cheap and purely additive to close now, before they
 * matter at scale, and two of the three are columns Round 2 itself leans
 * on harder (invoices.due_date for Phase 6's overdue-debt flagging,
 * attendances.date for Phase 4/5's payroll absenteeism + attendance
 * views). packaging_runs.run_start is filtered by every production
 * report's date range (see PackagingRunController::index()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('due_date');
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->index('date');
        });
        Schema::table('packaging_runs', function (Blueprint $table) {
            $table->index('run_start');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
        });
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['date']);
        });
        Schema::table('packaging_runs', function (Blueprint $table) {
            $table->dropIndex(['run_start']);
        });
    }
};

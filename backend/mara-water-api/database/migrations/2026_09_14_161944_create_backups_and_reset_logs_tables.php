<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (Danger Zone reset) foundation. Neither of these tables is ever
 * included in the reset's own wipe list -- see AdminController::WIPE_TABLES
 * -- so a backup and the record of who ran a reset both survive it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('created_by', 36)->nullable();
            $table->string('reason', 50)->default('manual'); // manual | pre_reset
            $table->json('table_row_counts');
            $table->unsignedBigInteger('size_bytes');
            $table->longText('payload'); // full JSON snapshot of every table listed in table_row_counts
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('reset_logs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('performed_by', 36)->nullable();
            $table->string('performed_by_email', 255); // kept redundantly in case the user row itself is ever removed later
            $table->char('backup_id', 36)->nullable();
            $table->json('tables_wiped');
            $table->json('row_counts_before');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reset_logs');
        Schema::dropIfExists('backups');
    }
};

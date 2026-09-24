<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round 5B:
 * - Material batch quality gate + qty_remaining for FIFO production drawdown
 * - material_batch_consumptions: which purchase batch(es) a packaging run used
 * - price_lists.list_kind: default (Director) vs corporate (Manager)
 * - stock_reconciliation_reports: scheduled/on-demand Excel metadata
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_batches', function (Blueprint $table) {
            $table->decimal('qty_remaining', 12, 3)->nullable()->after('qty_received');
            $table->string('quality_status', 20)->default('pending')->after('notes');
            $table->timestamp('quality_checked_at')->nullable()->after('quality_status');
            $table->uuid('quality_checked_by')->nullable()->after('quality_checked_at');
            $table->text('quality_notes')->nullable()->after('quality_checked_by');
            $table->foreign('quality_checked_by')->references('id')->on('users')->nullOnDelete();
        });

        // Existing rows: remaining = received, quality assumed passed (already in use).
        DB::table('material_batches')->whereNull('qty_remaining')->update([
            'qty_remaining' => DB::raw('qty_received'),
            'quality_status' => 'passed',
            'quality_checked_at' => DB::raw('created_at'),
        ]);

        Schema::create('material_batch_consumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('material_batch_id');
            $table->uuid('packaging_run_id');
            $table->uuid('material_id');
            $table->decimal('qty_consumed', 12, 3);
            $table->string('uom', 10);
            $table->timestamps();

            $table->foreign('material_batch_id')->references('id')->on('material_batches');
            $table->foreign('packaging_run_id')->references('id')->on('packaging_runs');
            $table->foreign('material_id')->references('id')->on('materials');
            $table->index(['packaging_run_id', 'material_id']);
        });

        Schema::table('price_lists', function (Blueprint $table) {
            $table->string('list_kind', 20)->default('other')->after('is_default');
        });

        // Seed list_kind from existing is_default / name conventions.
        DB::table('price_lists')->where('is_default', true)->update(['list_kind' => 'default']);
        DB::table('price_lists')->where('name', 'like', '%Corporate%')->update(['list_kind' => 'corporate']);
        DB::table('price_lists')->where('name', 'like', '%Wholesale%')->update(['list_kind' => 'corporate']);
        DB::table('price_lists')->where('name', 'like', '%Competitive%')->update(['list_kind' => 'corporate']);

        // Ensure at least one corporate list exists for Manager editing.
        $hasCorporate = DB::table('price_lists')->where('list_kind', 'corporate')->whereNull('deleted_at')->exists();
        if (!$hasCorporate) {
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('price_lists')->insert([
                'id' => $id,
                'name' => 'Corporate / Competitive Price List',
                'is_default' => false,
                'list_kind' => 'corporate',
                'valid_from' => '2025-10-01',
                'valid_to' => '2026-12-31',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Refresh default list validity window to match the standing retail period.
        DB::table('price_lists')->where('list_kind', 'default')->update([
            'valid_from' => '2025-10-01',
            'valid_to' => '2026-12-31',
        ]);

        Schema::create('stock_reconciliation_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('period_type', 20); // weekly | monthly | on_demand
            $table->date('period_start');
            $table->date('period_end');
            $table->uuid('warehouse_id')->nullable();
            $table->string('filename', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->longText('payload'); // base64 xlsx or binary-safe storage as longblob via string
            $table->string('generated_by', 36)->nullable(); // user id or 'scheduler'
            $table->timestamps();

            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->index(['period_type', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reconciliation_reports');

        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn('list_kind');
        });

        Schema::dropIfExists('material_batch_consumptions');

        Schema::table('material_batches', function (Blueprint $table) {
            $table->dropForeign(['quality_checked_by']);
            $table->dropColumn([
                'qty_remaining',
                'quality_status',
                'quality_checked_at',
                'quality_checked_by',
                'quality_notes',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops brief §3: Stock Arrival fields — bags in, bottles/bag (supplier+SKU),
 * bottles/bale (config), auto-computed bales_expected. Arrival type is
 * always Goods Received (single warehouse).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('material_batches')) {
            return;
        }

        Schema::table('material_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('material_batches', 'arrival_type')) {
                $table->string('arrival_type', 40)->default('goods_received')->after('batch_number');
            }
            if (!Schema::hasColumn('material_batches', 'sku_id')) {
                $table->uuid('sku_id')->nullable()->after('material_id');
            }
            if (!Schema::hasColumn('material_batches', 'bags_received')) {
                $table->decimal('bags_received', 12, 3)->nullable()->after('qty_received');
            }
            if (!Schema::hasColumn('material_batches', 'bottles_per_bag')) {
                $table->decimal('bottles_per_bag', 12, 3)->nullable()->after('bags_received');
            }
            if (!Schema::hasColumn('material_batches', 'bottles_per_bale')) {
                $table->decimal('bottles_per_bale', 12, 3)->nullable()->after('bottles_per_bag');
            }
            if (!Schema::hasColumn('material_batches', 'bales_expected')) {
                $table->decimal('bales_expected', 12, 3)->nullable()->after('bottles_per_bale');
            }
            if (!Schema::hasColumn('material_batches', 'document_path')) {
                $table->string('document_path', 500)->nullable()->after('notes');
            }
        });

        if (Schema::hasColumn('material_batches', 'sku_id') && Schema::hasTable('skus')) {
            Schema::table('material_batches', function (Blueprint $table) {
                // Safe if already added without FK
                try {
                    $table->foreign('sku_id')->references('id')->on('skus')->nullOnDelete();
                } catch (\Throwable $e) {
                    // FK may already exist
                }
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('material_batches')) {
            return;
        }
        Schema::table('material_batches', function (Blueprint $table) {
            foreach (['document_path', 'bales_expected', 'bottles_per_bale', 'bottles_per_bag', 'bags_received', 'sku_id', 'arrival_type'] as $col) {
                if (Schema::hasColumn('material_batches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

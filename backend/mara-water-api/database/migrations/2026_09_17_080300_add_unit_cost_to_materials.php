<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: costing needs a per-unit cost for each raw material so
 * per-bottle cost can be computed from the bill of materials (Phase 8)
 * -- qty_per_unit * material unit_cost, summed per SKU. Nullable/
 * additive: existing materials just cost 0 until someone fills this in
 * via the Materials UI, which is honest (unknown cost, not a guessed
 * one) rather than inventing a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            if (!Schema::hasColumn('materials', 'unit_cost')) {
                $table->decimal('unit_cost', 14, 4)->default(0)->after('uom');
            }
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};

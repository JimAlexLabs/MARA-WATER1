<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 (QA: Warehouse & Equipment Audit). The chemicals & water
 * testing log needs "chlorine stock with expiry dates" -- rather than
 * a parallel chemicals table, this adds expiry_date to the materials
 * a real chemical (CHLORINE) already exists there from an earlier
 * build. Any material can carry an expiry, not just chemicals, which
 * is more general than special-casing one category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            if (!Schema::hasColumn('materials', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('unit_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('expiry_date');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8: batches.code was varchar(20), but
 * BatchController::generateBatchCode() builds "BATCH-{skuCode}-{date}-
 * {seq}" -- e.g. "BATCH-PREM-1L-20260916-001" is 26 characters. Any SKU
 * code longer than a single character overflows the column, so batch
 * creation has been failing with a truncation SQLSTATE for every
 * realistic product code (found live while testing Phase 8's production
 * flow). Widening is purely additive -- no data can already be too long
 * for the new size, so this can't lose anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE batches MODIFY code VARCHAR(50) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE batches MODIFY code VARCHAR(20) NOT NULL');
    }
};

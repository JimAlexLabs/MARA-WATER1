<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7: orders.customer_id was NOT NULL, which blocked exactly the case
 * the spec calls for -- "linked customer (optional, required if credit)".
 * A walk-in cash/M-Pesa sale at an outlet counter has no customer record
 * to link. The existing draft-order flow (OrderController::store()) keeps
 * requiring a customer at the application/validation level regardless, so
 * this only loosens the column -- it doesn't change that flow's behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders MODIFY customer_id CHAR(36) NULL');
    }

    public function down(): void
    {
        // Any NULLs written while this was nullable would violate the
        // NOT NULL constraint on rollback -- refuse rather than silently
        // corrupt/drop data. Clean up orphaned rows manually first if this
        // ever needs to be rolled back.
        DB::statement('ALTER TABLE orders MODIFY customer_id CHAR(36) NOT NULL');
    }
};

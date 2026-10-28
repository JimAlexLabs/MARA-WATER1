<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-Pesa STK log-sale defers stock with status=awaiting_payment.
 * Production enum never included that value → Data truncated on insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY status ENUM(
            'draft',
            'confirmed',
            'dispatched',
            'delivered',
            'partially_returned',
            'cancelled',
            'awaiting_payment'
        ) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'awaiting_payment')->update(['status' => 'confirmed']);
        DB::statement("ALTER TABLE orders MODIFY status ENUM(
            'draft',
            'confirmed',
            'dispatched',
            'delivered',
            'partially_returned',
            'cancelled'
        ) NOT NULL DEFAULT 'draft'");
    }
};

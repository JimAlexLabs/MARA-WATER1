<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Direct Paybill (C2B): identity is the payer phone, account stays MARA.
 * Same mpesa_payments ledger as STK, plus a short-lived watch window.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mpesa_payments')) {
            // Channel was an enum in the first migration; C2B needs 'paybill'.
            try {
                DB::statement('ALTER TABLE mpesa_payments MODIFY channel VARCHAR(32) NOT NULL');
            } catch (\Throwable $e) {
                // SQLite / already a string — ignore.
            }
            try {
                DB::statement('ALTER TABLE mpesa_payments MODIFY reference VARCHAR(32) NOT NULL');
            } catch (\Throwable $e) {
                // ignore
            }

            Schema::table('mpesa_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('mpesa_payments', 'source')) {
                    $table->string('source', 12)->default('stk')->index();
                }
                if (! Schema::hasColumn('mpesa_payments', 'bill_ref')) {
                    $table->string('bill_ref', 32)->nullable();
                }
                if (! Schema::hasColumn('mpesa_payments', 'payer_name')) {
                    $table->string('payer_name', 80)->nullable();
                }
                if (! Schema::hasColumn('mpesa_payments', 'customer_id')) {
                    $table->uuid('customer_id')->nullable()->index();
                }
                if (! Schema::hasColumn('mpesa_payments', 'match_method')) {
                    $table->string('match_method', 24)->nullable();
                }
            });

            try {
                DB::table('mpesa_payments')->where('idempotency_key', 'like', 'c2b-%')->update(['source' => 'c2b']);
                DB::table('mpesa_payments')->whereIn('channel', ['paybill', 'unmatched'])->update(['source' => 'c2b']);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (! Schema::hasTable('paybill_watches')) {
            Schema::create('paybill_watches', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('customer_id')->nullable()->index();
                $table->string('phone', 15)->index();
                $table->decimal('amount', 12, 2)->nullable();
                $table->uuid('trip_id')->nullable()->index();
                $table->uuid('order_id')->nullable();
                $table->uuid('driver_trip_sale_id')->nullable();
                $table->uuid('initiated_by')->nullable()->index();
                $table->string('channel', 32)->nullable();
                $table->string('status', 16)->default('open')->index();
                $table->timestamp('expires_at')->index();
                $table->uuid('matched_payment_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paybill_watches');
        if (Schema::hasTable('mpesa_payments')) {
            Schema::table('mpesa_payments', function (Blueprint $table) {
                foreach (['source', 'bill_ref', 'payer_name', 'customer_id', 'match_method'] as $col) {
                    if (Schema::hasColumn('mpesa_payments', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};

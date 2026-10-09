<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mpesa_payments')) {
            Schema::create('mpesa_payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('order_id')->nullable()->index();
                $table->uuid('driver_trip_sale_id')->nullable()->index();
                $table->string('reference', 12)->index();
                $table->string('gateway_payment_id')->nullable()->index();
                $table->string('merchant_request_id')->nullable();
                $table->string('checkout_request_id')->nullable()->index();
                $table->string('phone', 15);
                $table->decimal('amount', 12, 2);
                $table->string('status', 20)->default('pending')->index();
                $table->string('result_code', 10)->nullable();
                $table->string('result_desc', 255)->nullable();
                $table->string('mpesa_receipt', 32)->nullable();
                $table->uuid('initiated_by')->nullable()->index();
                $table->string('channel', 20);
                $table->uuid('trip_id')->nullable();
                $table->string('location', 40)->nullable();
                $table->string('idempotency_key', 80)->unique();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('driver_trip_sales', function (Blueprint $table) {
            if (! Schema::hasColumn('driver_trip_sales', 'payment_status')) {
                $table->string('payment_status', 24)->default('paid')->after('payment_method');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 24)->default('paid')->after('payment_method');
            }
            if (! Schema::hasColumn('orders', 'stock_posted')) {
                $table->boolean('stock_posted')->default(true)->after('payment_status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_payments');
        Schema::table('driver_trip_sales', function (Blueprint $table) {
            if (Schema::hasColumn('driver_trip_sales', 'payment_status')) {
                $table->dropColumn('payment_status');
            }
        });
        Schema::table('orders', function (Blueprint $table) {
            foreach (['stock_posted', 'payment_status'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

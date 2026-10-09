<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mpesa_payments')) {
            return;
        }

        Schema::table('mpesa_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('mpesa_payments', 'location')) {
                $table->string('location', 40)->nullable();
            }
            if (! Schema::hasColumn('mpesa_payments', 'gateway_payment_id')) {
                $table->string('gateway_payment_id')->nullable()->index();
            }
            if (! Schema::hasColumn('mpesa_payments', 'checkout_request_id')) {
                $table->string('checkout_request_id')->nullable()->index();
            }
            if (! Schema::hasColumn('mpesa_payments', 'merchant_request_id')) {
                $table->string('merchant_request_id')->nullable();
            }
            if (! Schema::hasColumn('mpesa_payments', 'result_code')) {
                $table->string('result_code', 10)->nullable();
            }
            if (! Schema::hasColumn('mpesa_payments', 'result_desc')) {
                $table->string('result_desc', 255)->nullable();
            }
            if (! Schema::hasColumn('mpesa_payments', 'mpesa_receipt')) {
                $table->string('mpesa_receipt', 32)->nullable();
            }
            if (! Schema::hasColumn('mpesa_payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
            }
        });

        if (! Schema::hasTable('driver_trip_sales') || ! Schema::hasColumn('driver_trip_sales', 'payment_status')) {
            return;
        }

        $stale = DB::table('driver_trip_sales')
            ->where('payment_method', 'mpesa')
            ->where('payment_status', '!=', 'paid')
            ->whereNull('deleted_at')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('mpesa_payments')
                    ->whereColumn('mpesa_payments.driver_trip_sale_id', 'driver_trip_sales.id')
                    ->whereIn('mpesa_payments.status', ['pending', 'success']);
            })
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            DB::table('driver_trip_sale_items')->whereIn('driver_trip_sale_id', $stale)->delete();
            DB::table('driver_trip_sales')->whereIn('id', $stale)->update(['deleted_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('mpesa_payments') || ! Schema::hasColumn('mpesa_payments', 'location')) {
            return;
        }

        Schema::table('mpesa_payments', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};

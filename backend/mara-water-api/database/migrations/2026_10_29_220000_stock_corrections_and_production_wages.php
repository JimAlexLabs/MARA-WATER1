<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_correction_requests')) {
            Schema::create('stock_correction_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('requested_by')->nullable()->index();
                $table->string('status', 20)->default('pending')->index();
                $table->json('payload');
                $table->text('reason')->nullable();
                $table->uuid('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->uuid('stock_move_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('production_wage_entries')) {
            Schema::create('production_wage_entries', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->date('work_date')->index();
                $table->uuid('user_id')->index();
                $table->uuid('sku_id')->index();
                $table->string('kind', 16)->default('produce'); // produce | refill
                $table->decimal('bales', 12, 2)->default(0);
                $table->unsignedInteger('bottles_per_bale')->default(1);
                $table->decimal('wage_per_bottle', 12, 4)->default(0);
                $table->decimal('amount', 12, 2)->default(0);
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->unique(['work_date', 'user_id', 'sku_id', 'kind'], 'prod_wage_day_lady_sku_kind');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_wage_entries');
        Schema::dropIfExists('stock_correction_requests');
    }
};

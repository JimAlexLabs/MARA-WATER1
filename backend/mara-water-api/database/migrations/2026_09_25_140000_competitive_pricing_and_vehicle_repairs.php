<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ops brief §5: Competitive pricing is a comparison table (named
 * competitor companies × SKU), distinct from corporate negotiated rates.
 * Ops brief §2.5: vehicle repair costs (major/minor) visible to the
 * driver responsible for that vehicle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('competitor_companies')) {
            Schema::create('competitor_companies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name', 120)->unique();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('competitor_prices')) {
            Schema::create('competitor_prices', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('competitor_company_id');
                $table->uuid('sku_id');
                $table->decimal('unit_price', 12, 2);
                $table->date('effective_date')->nullable();
                $table->string('notes', 500)->nullable();
                $table->uuid('logged_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('competitor_company_id')->references('id')->on('competitor_companies')->cascadeOnDelete();
                $table->foreign('sku_id')->references('id')->on('skus')->cascadeOnDelete();
                $table->unique(['competitor_company_id', 'sku_id'], 'competitor_sku_unique');
            });
        }

        if (!Schema::hasTable('vehicle_repairs')) {
            Schema::create('vehicle_repairs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('vehicle_id');
                $table->date('repair_date');
                $table->string('category', 20); // major | minor
                $table->string('description', 500);
                $table->decimal('cost', 12, 2);
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
                $table->index(['vehicle_id', 'repair_date']);
            });
        }

        // Seed at least three named competitor companies (brief §5).
        $seeded = [
            'Aquamist',
            'Highland',
            'Keringet',
        ];
        foreach ($seeded as $name) {
            $exists = DB::table('competitor_companies')->where('name', $name)->exists();
            if (!$exists) {
                DB::table('competitor_companies')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => $name,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Rename conflated corporate list so UX is clear.
        DB::table('price_lists')
            ->where('list_kind', 'corporate')
            ->where('name', 'like', '%Competitive%')
            ->update(['name' => 'Corporate / Wholesale Price List']);
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_prices');
        Schema::dropIfExists('competitor_companies');
        Schema::dropIfExists('vehicle_repairs');
    }
};

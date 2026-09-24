<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Operations overview: bag packaging on material batches, FineLine /
 * Blowplast supplier seed rows, and per-supplier SKU bag→bale conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('material_batches') && !Schema::hasColumn('material_batches', 'package_unit')) {
            Schema::table('material_batches', function (Blueprint $table) {
                $table->string('package_unit', 20)->default('bag')->after('qty_received');
                $table->string('supplier_code', 40)->nullable()->after('supplier_name');
                $table->decimal('transport_cost', 12, 2)->nullable()->after('unit_cost');
            });
        }

        if (!Schema::hasTable('sku_package_conversions')) {
            Schema::create('sku_package_conversions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('supplier_code', 40); // FINELINE | BLOWPLAST | OTHER
                $table->uuid('sku_id');
                $table->decimal('bottles_per_bag', 12, 3)->default(24);
                $table->decimal('bottles_per_bale', 12, 3)->default(12);
                $table->text('notes')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('sku_id')->references('id')->on('skus');
                $table->unique(['supplier_code', 'sku_id']);
            });
        }

        // Seed FineLine / Blowplast by name (suppliers table has no code column).
        if (Schema::hasTable('suppliers')) {
            $now = now();
            $cols = Schema::getColumnListing('suppliers');
            foreach ([
                ['name' => 'FineLine', 'contact_name' => 'FineLine Nairobi', 'address' => 'Nairobi'],
                ['name' => 'Blowplast', 'contact_name' => 'Blowplast Nairobi', 'address' => 'Nairobi'],
            ] as $s) {
                $exists = DB::table('suppliers')->where('name', $s['name'])->exists();
                if ($exists) {
                    continue;
                }
                $row = array_merge($s, [
                    'id' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('suppliers')->insert(array_intersect_key($row, array_flip($cols)));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_package_conversions');
        if (Schema::hasTable('material_batches') && Schema::hasColumn('material_batches', 'package_unit')) {
            Schema::table('material_batches', function (Blueprint $table) {
                $table->dropColumn(['package_unit', 'supplier_code', 'transport_cost']);
            });
        }
    }
};

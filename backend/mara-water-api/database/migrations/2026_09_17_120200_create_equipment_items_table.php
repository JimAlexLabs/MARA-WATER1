<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 10: equipment & machinery (batching machine, heat guns, booster
 * pumps/valves, production basins, backwash system) and test equipment
 * (pH tester) don't fit the materials/BOM model -- they aren't consumed
 * per unit produced, and need condition/maintenance/calibration fields
 * a raw material has no business carrying. A small dedicated table.
 *
 * Starter rows are seeded with name/category/unit only -- qty_on_hand,
 * condition, and service dates are left null ("not yet recorded")
 * rather than guessed, since those are real physical facts only
 * someone doing the actual audit walk-through can supply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('category', ['equipment', 'test_equipment']);
            $table->string('name', 200);
            $table->string('unit', 20)->default('PCS');
            $table->integer('qty_on_hand')->nullable();
            $table->integer('minimum_required')->nullable();
            $table->enum('condition', ['good', 'fair', 'poor', 'broken'])->nullable();
            $table->date('last_service_date')->nullable();
            $table->date('next_service_due')->nullable();
            $table->enum('calibration_status', ['calibrated', 'due', 'not_calibrated'])->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        $now = now();
        $rows = [
            ['name' => 'Batching Machine', 'category' => 'equipment', 'unit' => 'PCS'],
            ['name' => 'Heat Guns', 'category' => 'equipment', 'unit' => 'PCS'],
            ['name' => 'Booster Pumps/Valves', 'category' => 'equipment', 'unit' => 'PCS'],
            ['name' => 'Production Basins', 'category' => 'equipment', 'unit' => 'PCS'],
            ['name' => 'Backwash System', 'category' => 'equipment', 'unit' => 'PCS'],
            ['name' => 'pH Tester', 'category' => 'test_equipment', 'unit' => 'PCS'],
        ];

        foreach ($rows as &$row) {
            $row['id'] = (string) Str::uuid();
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('equipment_items')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_items');
    }
};

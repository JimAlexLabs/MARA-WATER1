<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 8: the seeded skus table only ever had 5 generic "Mara Water"
 * bottles, but the spec's production section names Homa Springs' real
 * product lines by brand and size -- Premium (255ml/1L/1.5L/5L/10L/20L),
 * Platinum (0.5L/1L), Grace (0.5L/1L), Refill (5L/10L/20L). Without these
 * as real SKUs, "production logging per brand per size" and "refills
 * tracking" have nothing to attach to.
 *
 * These codes/names/thresholds are inferred from the spec text, not
 * confirmed against Homa Springs' actual product master -- flagged
 * explicitly in the Phase 8 report. The 5 original WATER-* SKUs are left
 * untouched (insertOrIgnore, keyed on the unique `code`), since orders/
 * price lists/batches already reference them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            // Premium line
            ['code' => 'PREM-0.255L', 'name' => 'Premium 255ml Bottle',  'brand' => 'Premium',  'size_liters' => 0.255, 'unit' => 'BOTTLE',    'reorder_threshold' => 300],
            ['code' => 'PREM-1L',     'name' => 'Premium 1L Bottle',     'brand' => 'Premium',  'size_liters' => 1.00,  'unit' => 'BOTTLE',    'reorder_threshold' => 200],
            ['code' => 'PREM-1.5L',   'name' => 'Premium 1.5L Bottle',   'brand' => 'Premium',  'size_liters' => 1.50,  'unit' => 'BOTTLE',    'reorder_threshold' => 200],
            ['code' => 'PREM-5L',     'name' => 'Premium 5L Container',  'brand' => 'Premium',  'size_liters' => 5.00,  'unit' => 'CONTAINER', 'reorder_threshold' => 80],
            ['code' => 'PREM-10L',    'name' => 'Premium 10L Container', 'brand' => 'Premium',  'size_liters' => 10.00, 'unit' => 'CONTAINER', 'reorder_threshold' => 50],
            ['code' => 'PREM-20L',    'name' => 'Premium 20L Container', 'brand' => 'Premium',  'size_liters' => 20.00, 'unit' => 'CONTAINER', 'reorder_threshold' => 50],
            // Platinum line
            ['code' => 'PLAT-0.5L',   'name' => 'Platinum 0.5L Bottle',  'brand' => 'Platinum', 'size_liters' => 0.50,  'unit' => 'BOTTLE',    'reorder_threshold' => 300],
            ['code' => 'PLAT-1L',     'name' => 'Platinum 1L Bottle',    'brand' => 'Platinum', 'size_liters' => 1.00,  'unit' => 'BOTTLE',    'reorder_threshold' => 200],
            // Grace line
            ['code' => 'GRACE-0.5L',  'name' => 'Grace 0.5L Bottle',     'brand' => 'Grace',    'size_liters' => 0.50,  'unit' => 'BOTTLE',    'reorder_threshold' => 300],
            ['code' => 'GRACE-1L',    'name' => 'Grace 1L Bottle',       'brand' => 'Grace',    'size_liters' => 1.00,  'unit' => 'BOTTLE',    'reorder_threshold' => 200],
            // Refill line (returnable jerricans, sold to standing customers)
            ['code' => 'REFILL-5L',   'name' => 'Refill 5L Container',   'brand' => 'Refill',   'size_liters' => 5.00,  'unit' => 'CONTAINER', 'reorder_threshold' => 60],
            ['code' => 'REFILL-10L',  'name' => 'Refill 10L Container',  'brand' => 'Refill',   'size_liters' => 10.00, 'unit' => 'CONTAINER', 'reorder_threshold' => 40],
            ['code' => 'REFILL-20L',  'name' => 'Refill 20L Container',  'brand' => 'Refill',   'size_liters' => 20.00, 'unit' => 'CONTAINER', 'reorder_threshold' => 40],
        ];

        // foreach-by-reference matters here: without the `&`, mutations to
        // $row are local to the loop and never make it back into $rows,
        // which silently sends every row through with no `id` at all.
        foreach ($rows as &$row) {
            $row['id'] = (string) Str::uuid();
            $row['expiry_days'] = 365;
            $row['active'] = 1;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        DB::table('skus')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('skus')->whereIn('code', [
            'PREM-0.255L', 'PREM-1L', 'PREM-1.5L', 'PREM-5L', 'PREM-10L', 'PREM-20L',
            'PLAT-0.5L', 'PLAT-1L',
            'GRACE-0.5L', 'GRACE-1L',
            'REFILL-5L', 'REFILL-10L', 'REFILL-20L',
        ])->delete();
    }
};

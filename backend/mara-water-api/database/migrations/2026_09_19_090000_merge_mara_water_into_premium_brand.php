<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Round 4 Phase 0, decision 2: "Premium and 'Mara Water' are the same
 * product line... merge them everywhere... keep the name Premium as the
 * canonical label... this is a data migration, not just a UI change."
 *
 * The five original WATER-* SKUs (seeded before the real product-brand
 * catalog existed -- see 2026_09_16_090100_seed_real_product_brand_catalog's
 * own docblock, which deliberately left them untouched "since orders/
 * price lists/batches already reference them") turn out to have an
 * exact-size PREM-* counterpart for every one of them:
 *   WATER-0.5L -> PREM-CUSTOM-0.5L, WATER-1L -> PREM-1L,
 *   WATER-1.5L -> PREM-1.5L, WATER-5L -> PREM-5L, WATER-20L -> PREM-20L.
 * "Mara Water" was never a real second brand -- it's a NULL-brand
 * fallback label the app's own code was rendering (`$sku->brand ??
 * 'Mara Water'`) for these five rows. So this isn't a rename, it's a
 * real merge: every historical row across the 6 tables that actually
 * reference these 5 sku_ids gets retargeted onto its PREM-* twin, then
 * the WATER-* SKUs are deactivated (not deleted -- old records referring
 * to their id by FK stay resolvable).
 *
 * price_list_items collides on (price_list_id, sku_id): every PREM-*
 * twin already has a real current price there (370-450 KES) that's
 * wildly different from the WATER-* rows' (25-750 KES, clearly stale
 * placeholder data from the original scaffold seed) -- so those WATER-*
 * price rows are dropped rather than merged; the real PREM-* price
 * wins. bom_items and stock_items are defensively checked for the same
 * kind of collision (none currently exists) and merged/summed rather
 * than blindly overwritten if one ever does.
 */
return new class extends Migration
{
    private const MAP = [
        'WATER-0.5L' => 'PREM-CUSTOM-0.5L',
        'WATER-1L' => 'PREM-1L',
        'WATER-1.5L' => 'PREM-1.5L',
        'WATER-5L' => 'PREM-5L',
        'WATER-20L' => 'PREM-20L',
    ];

    public function up(): void
    {
        $skusByCode = DB::table('skus')
            ->whereIn('code', array_merge(array_keys(self::MAP), array_values(self::MAP)))
            ->pluck('id', 'code');

        DB::transaction(function () use ($skusByCode) {
            foreach (self::MAP as $waterCode => $premCode) {
                if (!isset($skusByCode[$waterCode]) || !isset($skusByCode[$premCode])) {
                    continue; // one side missing (e.g. re-run after a partial cleanup) -- skip, not fatal.
                }
                $waterId = $skusByCode[$waterCode];
                $premId = $skusByCode[$premCode];

                // price_list_items: unique(price_list_id, sku_id). The
                // PREM-* row is the real current price; drop the WATER-*
                // one outright rather than fight the constraint.
                DB::table('price_list_items')->where('sku_id', $waterId)->delete();

                // bom_items: unique(sku_id, material_id). Merge -- if the
                // PREM-* twin already has a BOM row for the same material,
                // keep that one and drop the WATER-* duplicate; otherwise
                // just retarget.
                foreach (DB::table('bom_items')->where('sku_id', $waterId)->get() as $row) {
                    $exists = DB::table('bom_items')->where('sku_id', $premId)->where('material_id', $row->material_id)->exists();
                    if ($exists) {
                        DB::table('bom_items')->where('id', $row->id)->delete();
                    } else {
                        DB::table('bom_items')->where('id', $row->id)->update(['sku_id' => $premId]);
                    }
                }

                // stock_items: unique(item_type, material_id, sku_id,
                // warehouse_id, batch_id). Merge quantities onto the
                // PREM-* row for the same warehouse/batch if one exists,
                // otherwise retarget.
                foreach (DB::table('stock_items')->where('sku_id', $waterId)->get() as $row) {
                    $existing = DB::table('stock_items')
                        ->where('item_type', $row->item_type)->where('sku_id', $premId)
                        ->where('warehouse_id', $row->warehouse_id)
                        ->where(function ($q) use ($row) {
                            $row->batch_id === null ? $q->whereNull('batch_id') : $q->where('batch_id', $row->batch_id);
                        })->first();
                    if ($existing) {
                        DB::table('stock_items')->where('id', $existing->id)->update(['qty' => $existing->qty + $row->qty]);
                        DB::table('stock_items')->where('id', $row->id)->delete();
                    } else {
                        DB::table('stock_items')->where('id', $row->id)->update(['sku_id' => $premId]);
                    }
                }

                // Plain transaction logs / line items -- no unique
                // constraint on sku_id, safe to retarget directly.
                DB::table('stock_moves')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('order_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('driver_trip_sale_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('driver_trip_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('invoice_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('manifest_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('packaging_runs')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('price_agreements')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('production_plan_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('return_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('stock_count_items')->where('sku_id', $waterId)->update(['sku_id' => $premId]);
                DB::table('batches')->where('sku_id', $waterId)->update(['sku_id' => $premId]);

                // Retire the WATER-* SKU -- deactivated, not deleted, so
                // any FK that still somehow points at it (there shouldn't
                // be any left after the above) stays resolvable.
                DB::table('skus')->where('id', $waterId)->update(['active' => false]);
            }
        });
    }

    /**
     * Not meaningfully reversible -- this is a real data merge (rows
     * were retargeted/summed/dropped, not just relabeled), so unwinding
     * it would mean guessing which merged rows used to belong to which
     * SKU. Re-activating the WATER-* SKUs is the only safe partial
     * rollback.
     */
    public function down(): void
    {
        DB::table('skus')->whereIn('code', array_keys(self::MAP))->update(['active' => true]);
    }
};
